<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Entity\Vote;
use JuryTool\Domain\Enum\RoundState;
use JuryTool\Domain\Enum\SourceType;
use JuryTool\Domain\Enum\VotingMethod;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Service\ActivityLogger;
use JuryTool\Domain\Entity\RoundSource;
use JuryTool\Service\AccessControl;
use JuryTool\Service\ImportService;
use JuryTool\Service\AssignmentService;
use JuryTool\Service\MeetingService;
use JuryTool\Service\RoundDerivationService;
use JuryTool\Service\RoundPopulationService;
use JuryTool\Service\StatisticsService;
use JuryTool\Support\DerivationCriteria;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Round configuration, lifecycle, jurors, statistics and exports.
 */
class RoundActions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoundPopulationService $population,
        private readonly RoundDerivationService $derivation,
        private readonly StatisticsService $statistics,
        private readonly AssignmentService $assignments,
        private readonly ActivityLogger $log,
        private readonly MeetingService $meetings,
        private readonly AccessControl $access,
        private readonly ImportService $import,
    ) {
    }

    /**
     * Imports this round's own Commons source.
     *
     * Parallel rounds of a campaign — Trees, Rivers — each gather their own
     * category, so importing belongs to the round rather than the campaign.
     */
    public function import(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $actor = $this->actor($request);

        if ($actor !== null) {
            $this->access->requireOrganizer($actor, $round->getCampaign());
        }

        $outcome = $this->import->importRoundSource($round, $actor);
        $population = $this->population->populate($round, $outcome['images']);

        $this->log->record(
            $actor,
            'round.import',
            sprintf(
                'Imported %d file(s) into "%s"',
                $outcome['result']->processed,
                $round->getName(),
            ),
            'Round',
            $round->getId(),
            $outcome['result']->toArray(),
            $request,
        );

        return Json::write($response, [
            'import' => $outcome['result']->toArray(),
            'warnings' => $outcome['warnings'],
            'population' => $population->toArray(),
            'sources' => $this->sources($round),
        ]);
    }

    /**
     * Retries an import that failed or stopped partway.
     *
     * Large categories can take minutes and fail on a timeout; because the
     * pool matches on Commons page id, a retry resumes rather than
     * duplicating what was already fetched.
     */
    public function retryImport(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $actor = $this->actor($request);

        if ($actor !== null) {
            $this->access->requireOrganizer($actor, $round->getCampaign());
        }

        $source = $this->em->getRepository(RoundSource::class)->find((int) $args['sourceId']);

        if ($source === null || $source->getRound()->getId() !== $round->getId()) {
            throw DomainException::notFound('Import');
        }

        $outcome = $this->import->retryRoundSource($source);
        $population = $this->population->populate($round, $outcome['images']);

        $this->log->record(
            $actor,
            'round.import_retry',
            sprintf(
                'Retried import of "%s" (attempt %d)',
                $round->getName(),
                $source->getAttemptCount(),
            ),
            'Round',
            $round->getId(),
            request: $request,
        );

        return Json::write($response, [
            'import' => $outcome['result']->toArray(),
            'warnings' => $outcome['warnings'],
            'population' => $population->toArray(),
            'sources' => $this->sources($round),
        ]);
    }

    /**
     * Candidate score thresholds with the resulting image count, so a
     * coordinator can pick a cutoff by the shortlist size it produces.
     */
    public function thresholds(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);

        return Json::write($response, [
            'thresholds' => $this->derivation->thresholdOptions($round),
        ]);
    }

    /** The round's import history, including any that failed. */
    private function sources(Round $round): array
    {
        $sources = $this->em->getRepository(RoundSource::class)
            ->findBy(['round' => $round], ['importedAt' => 'DESC']);

        return array_map(
            static fn (RoundSource $s): array => [
                'id' => $s->getId(),
                'summary' => $s->summary(),
                'filesSeen' => $s->getFilesSeen(),
                'filesAdded' => $s->getFilesAdded(),
                'isComplete' => $s->isComplete(),
                'hasFailed' => $s->hasFailed(),
                'errorMessage' => $s->getErrorMessage(),
                'attemptCount' => $s->getAttemptCount(),
                'warnings' => $s->getWarnings(),
                'importedBy' => $s->getImportedBy(),
                'importedAt' => $s->getImportedAt()->format(\DateTimeInterface::ATOM),
            ],
            $sources,
        );
    }

    /**
     * Opens a final jury meeting on this round's results.
     *
     * The panel that judged the round carries over and agrees one shared
     * ranking together, rather than voting again independently.
     */
    public function createMeeting(Request $request, Response $response, array $args): Response
    {
        $source = $this->find($args['id']);
        $body = Json::body($request);

        $topN = Json::int($body, 'topN', 0);

        $meeting = $this->meetings->createFromRound(
            $source,
            Json::optionalString($body, 'name') ?? ($source->getName() . ' — final meeting'),
            $topN > 0 ? $topN : null,
        );

        $this->log->record(
            $this->actor($request),
            'meeting.create',
            sprintf('Opened jury meeting "%s" on %s', $meeting->getName(), $source->getName()),
            'Round',
            $meeting->getId(),
            request: $request,
        );

        return Json::write($response, ['round' => Presenter::round($meeting)], 201);
    }

    /** The signed-in user, for audit entries. */
    private function actor(Request $request): ?User
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        return $actor instanceof User ? $actor : null;
    }

    /**
     * Re-deals images across the panel.
     *
     * Activation allocates automatically; this is for afterwards — when a
     * juror is added, or more images are imported, and the new work needs
     * spreading across the round.
     */
    public function allocate(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);

        if ($round->getState()->isFinal()) {
            throw new DomainException('A finalized round cannot be re-allocated.');
        }

        $result = $this->assignments->allocate($round);

        return Json::write($response, [
            'allocation' => $result,
            'jurors' => $this->statistics->jurorProgress($round),
        ]);
    }

    /** Full round view for the coordinator dashboard. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);

        return Json::write($response, [
            'round' => Presenter::round($round),
            'statistics' => $this->statistics->roundSummary($round),
            'jurors' => $this->statistics->jurorProgress($round),
            'sources' => $this->sources($round),
        ]);
    }

    /**
     * Creates a round in the campaign and fills it from the campaign's
     * master pool.
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->em->getRepository(Campaign::class)->find((int) $args['campaignId']);

        if ($campaign === null) {
            throw DomainException::notFound('Campaign');
        }

        $body = Json::body($request);

        $round = new Round($campaign, Json::requireString($body, 'name'));
        $this->applySettings($round, $body);

        $this->em->persist($round);
        $this->em->flush();

        $this->applyJurors($round, $body, $this->actor($request));

        $population = null;

        if (Json::bool($body, 'populate', true)) {
            $population = $this->population->populateFromCampaignPool($round)->toArray();
        }

        $this->log->record(
            $this->actor($request),
            'round.create',
            sprintf('Created round "%s" in %s', $round->getName(), $campaign->getName()),
            'Round',
            $round->getId(),
            $population,
            $request,
        );

        return Json::write($response, [
            'round' => Presenter::round($round),
            'population' => $population,
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);

        if ($round->getState()->isFinal()) {
            throw new DomainException('A finalized round can no longer be edited.');
        }

        $body = Json::body($request);

        if (($name = Json::optionalString($body, 'name')) !== null) {
            $round->setName($name);
        }

        $this->applySettings($round, $body);
        $this->applyJurors($round, $body, $this->actor($request));

        $this->em->flush();

        // Changing the file settings changes which images qualify, so the
        // rules are re-applied to images that have not yet been voted on.
        $reapplied = null;

        if (isset($body['fileSettings'])) {
            $reapplied = $this->population->reapplyRules($round)->toArray();
        }

        return Json::write($response, [
            'round' => Presenter::round($round),
            'reapplied' => $reapplied,
        ]);
    }

    /** Moves the round through its lifecycle: activate, pause, finalize. */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $target = RoundState::tryFrom((string) ($args['state'] ?? ''));

        if ($target === null) {
            throw DomainException::badRequest('Unknown round state.');
        }

        $previous = $round->getState();
        $this->population->transition($round, $target);

        $this->log->record(
            $this->actor($request),
            'round.' . $target->value,
            sprintf(
                'Moved round "%s" from %s to %s',
                $round->getName(),
                $previous->value,
                $target->value,
            ),
            'Round',
            $round->getId(),
            request: $request,
        );

        return Json::write($response, [
            'round' => Presenter::round($round),
            'statistics' => $this->statistics->roundSummary($round),
        ]);
    }

    /**
     * Hands a juror's seat to a different Wikimedia user.
     *
     * Used when someone stops participating mid-round. The votes already
     * cast on that seat move with it, so quorum counts stay intact and the
     * incoming juror can revise any inherited vote if it is disputed.
     */
    public function replaceJuror(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);

        if ($round->getState()->isFinal()) {
            throw new DomainException('Jurors cannot be changed on a finalized round.');
        }

        $juror = $this->em->getRepository(RoundJuror::class)->find((int) $args['jurorId']);

        if ($juror === null || $juror->getRound()->getId() !== $round->getId()) {
            throw DomainException::notFound('Juror');
        }

        $actor = $this->actor($request);

        if ($actor !== null) {
            $this->access->requireOrganizer($actor, $round->getCampaign());
        }

        $username = User::canonicaliseUsername(
            Json::requireString(Json::body($request), 'username')
        );

        foreach ($round->getJurors() as $existing) {
            if ($existing->getId() !== $juror->getId() && $existing->getUsername() === $username) {
                throw DomainException::badRequest(
                    sprintf('%s is already a juror in this round.', $username)
                );
            }
        }

        $previous = $juror->getUsername();
        $juror->replaceWith($username);

        $this->em->flush();

        $this->log->record(
            $this->actor($request),
            'round.replace_juror',
            sprintf(
                'Replaced juror %s with %s in round "%s"',
                $previous,
                $username,
                $round->getName(),
            ),
            'Round',
            $round->getId(),
            ['from' => $previous, 'to' => $username],
            $request,
        );

        return Json::write($response, [
            'juror' => [
                'id' => $juror->getId(),
                'username' => $juror->getUsername(),
                'replacedUsername' => $juror->getReplacedUsername(),
            ],
            'message' => sprintf(
                'Replaced %s with %s. Votes already cast were transferred and can be edited.',
                $previous,
                $username,
            ),
            'jurors' => $this->statistics->jurorProgress($round),
        ]);
    }

    /** Images in the round, with their disqualification status. */
    public function images(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $query = $request->getQueryParams();

        $criteria = ['round' => $round];

        if (isset($query['disqualified'])) {
            $criteria['isDisqualified'] = filter_var($query['disqualified'], FILTER_VALIDATE_BOOL);
        }

        $limit = min(500, max(1, (int) ($query['limit'] ?? 100)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $images = $this->em->getRepository(RoundImage::class)
            ->findBy($criteria, ['id' => 'ASC'], $limit, $offset);

        $total = (int) $this->em->createQuery(
            'SELECT COUNT(ri.id) FROM ' . RoundImage::class . ' ri WHERE ri.round = :round'
            . (isset($criteria['isDisqualified']) ? ' AND ri.isDisqualified = :dq' : '')
        )->setParameters($criteria + (isset($criteria['isDisqualified'])
            ? ['dq' => $criteria['isDisqualified']]
            : []))->getSingleScalarResult();

        return Json::write($response, [
            'images' => array_map(
                static fn (RoundImage $i): array => Presenter::imageForAdmin($i),
                $images,
            ),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Ranked results for the round. */
    public function results(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $includeDisqualified = filter_var(
            $request->getQueryParams()['includeDisqualified'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        return Json::write($response, [
            'round' => Presenter::round($round),
            'results' => $this->statistics->results($round, $includeDisqualified),
        ]);
    }

    /**
     * Downloads results as CSV, or the plain entry list as text — the two
     * export shapes coordinators need for reporting and for feeding the
     * next stage of a contest.
     */
    public function export(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'csv'));
        $results = $this->statistics->results($round);

        $slug = preg_replace('/[^a-z0-9]+/i', '-', $round->getName()) ?? 'round';

        if ($format === 'txt') {
            // One "File:Name" per line, the format Commons galleries and
            // the legacy tooling both consume.
            $lines = array_map(
                static fn (array $r): string => $r['title'],
                $results,
            );

            $response->getBody()->write(implode("\n", $lines) . "\n");

            return $response
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', "attachment; filename=\"$slug-entries.txt\"");
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['position', 'title', 'uploader', 'votes', 'average', 'total', 'resolution', 'url'], escape: '');

        foreach ($results as $row) {
            fputcsv($out, [
                $row['position'],
                $row['title'],
                $row['uploader'],
                $row['voteCount'],
                $row['averageScore'],
                $row['totalScore'],
                $row['width'] . 'x' . $row['height'],
                $row['descriptionUrl'],
            ], escape: '');
        }

        rewind($out);
        $response->getBody()->write((string) stream_get_contents($out));
        fclose($out);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"$slug-results.csv\"");
    }

    /** Previews how many images given criteria would carry to a new round. */
    public function previewDerivation(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $criteria = DerivationCriteria::fromArray(Json::body($request));

        return Json::write($response, [
            'count' => $this->derivation->previewCount($round, $criteria),
            'criteria' => $criteria->describe($round->getVotingMethod(), $round->getMaxRating()),
        ]);
    }

    /** Creates the next round from this one's results. */
    public function derive(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);
        $body = Json::body($request);

        $derived = $this->derivation->derive(
            $round,
            Json::requireString($body, 'name'),
            DerivationCriteria::fromArray($body),
        );

        return Json::write($response, [
            'round' => Presenter::round($derived),
            'statistics' => $this->statistics->roundSummary($derived),
        ], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $round = $this->find($args['id']);

        $name = $round->getName();
        $id = $round->getId();
        $actorId = $this->actor($request)?->getId();

        // Counted with a query rather than through $round->getImages().
        // Reading that collection loads the rows into the entity manager,
        // and the database — not Doctrine — is what cascades the delete, so
        // they would survive the remove() and the next flush would try to
        // re-persist them against a round that is gone.
        $imageCount = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM ' . RoundImage::class . ' i WHERE i.round = :round'
        )->setParameter('round', $round)->getSingleScalarResult();

        $this->em->remove($round);
        $this->em->flush();

        // Anything the database cascaded away must not linger in the
        // identity map when the log below flushes.
        $this->em->clear();

        $this->log->record(
            $actorId === null ? null : $this->em->getRepository(User::class)->find($actorId),
            'round.delete',
            sprintf('Deleted round "%s" and its %d image(s)', $name, $imageCount),
            'Round',
            $id,
            ['images' => $imageCount],
            $request,
        );

        return Json::write($response, ['ok' => true]);
    }

    /** @param array<string, mixed> $body */
    private function applySettings(Round $round, array $body): void
    {
        // Each round gathers its own Commons category — a campaign such as
        // "WLE 2026 in India" is judged as parallel rounds for Trees and
        // Rivers, so the source belongs here rather than on the campaign.
        if (array_key_exists('sourceType', $body)) {
            $type = SourceType::tryFrom((string) $body['sourceType']);

            if ($type === null || $type === SourceType::PreviousRound) {
                throw DomainException::badRequest('Invalid source type for a round.');
            }

            $round->setSourceType($type);
        }

        if (array_key_exists('sourceCategory', $body)) {
            $round->setSourceCategory(Json::optionalString($body, 'sourceCategory'));
        }

        if (array_key_exists('sourceUrl', $body)) {
            $round->setSourceUrl(Json::optionalString($body, 'sourceUrl'));
        }

        if (array_key_exists('sourceFileList', $body)) {
            $round->setSourceFileList(Json::optionalString($body, 'sourceFileList'));
        }

        if (array_key_exists('details', $body)) {
            $round->setDetails(Json::optionalString($body, 'details'));
        }

        if (array_key_exists('votingDeadline', $body)) {
            $round->setVotingDeadline(Json::date($body, 'votingDeadline'));
        }

        if (array_key_exists('votingMethod', $body)) {
            $method = VotingMethod::tryFrom((string) $body['votingMethod']);

            if ($method === null) {
                throw DomainException::badRequest('Unknown voting method.');
            }

            $round->setVotingMethod($method);
        }

        if (array_key_exists('maxRating', $body)) {
            $max = Json::int($body, 'maxRating', 5);

            if ($max < 2 || $max > 10) {
                throw DomainException::badRequest('Maximum rating must be between 2 and 10.');
            }

            $round->setMaxRating($max);
        }

        if (array_key_exists('quorum', $body)) {
            $round->setQuorum(Json::int($body, 'quorum', 1));
        }

        if (array_key_exists('showOwnStatistics', $body)) {
            $round->setShowOwnStatistics(Json::bool($body, 'showOwnStatistics'));
        }

        if (!isset($body['fileSettings']) || !is_array($body['fileSettings'])) {
            return;
        }

        $fs = $body['fileSettings'];
        $settings = $round->getFileSettings();

        $settings->setDisqualifyJurors(Json::bool($fs, 'disqualifyJurors', $settings->disqualifiesJurors()));
        $settings->setDisqualifyByResolution(Json::bool($fs, 'disqualifyByResolution', $settings->disqualifiesByResolution()));
        $settings->setDisqualifyByUploadDate(Json::bool($fs, 'disqualifyByUploadDate', $settings->disqualifiesByUploadDate()));
        $settings->setDisqualifyCoordinators(Json::bool($fs, 'disqualifyCoordinators', $settings->disqualifiesCoordinators()));
        $settings->setDisqualifyMaintainers(Json::bool($fs, 'disqualifyMaintainers', $settings->disqualifiesMaintainers()));
        $settings->setDisqualifyOrganizers(Json::bool($fs, 'disqualifyOrganizers', $settings->disqualifiesOrganizers()));
        $settings->setShowFilename(Json::bool($fs, 'showFilename', $settings->showsFilename()));
        $settings->setShowLink(Json::bool($fs, 'showLink', $settings->showsLink()));
        $settings->setShowResolution(Json::bool($fs, 'showResolution', $settings->showsResolution()));

        if (array_key_exists('minResolutionPixels', $fs)) {
            $settings->setMinResolutionPixels(
                Json::int($fs, 'minResolutionPixels', $settings->getMinResolutionPixels())
            );
        }

        if (array_key_exists('uploadDateFrom', $fs)) {
            $settings->setUploadDateFrom(Json::date($fs, 'uploadDateFrom'));
        }

        if (array_key_exists('uploadDateTo', $fs)) {
            $settings->setUploadDateTo(Json::date($fs, 'uploadDateTo'));
        }
    }

    /**
     * Replaces the juror roster when the request carries one.
     *
     * Jurors who already cast votes are deactivated rather than deleted, so
     * removing someone from the panel never destroys their work.
     *
     * @param array<string, mixed> $body
     */
    private function applyJurors(Round $round, array $body, ?User $actor = null): void
    {
        if (!isset($body['jurors']) || !is_array($body['jurors'])) {
            return;
        }

        // Organizers run the campaign's rounds and choose who judges them;
        // the lead and admins can do the same, since both outrank them.
        if ($actor !== null) {
            $this->access->requireOrganizer($actor, $round->getCampaign());
        }

        // Yes/no and rating rounds deal specific images to specific jurors
        // on activation, so changing the panel mid-round would strand or
        // duplicate assignments. Ranking rounds give every juror the whole
        // set, so their panel can be edited freely while they run — which
        // matters, because the people who do the ranking assessment are
        // often decided after the round has started.
        if (
            $round->getState() === RoundState::Active
            && $round->getVotingMethod() !== VotingMethod::RankOrder
        ) {
            throw new DomainException(
                'Pause the round before changing its jurors, '
                . 'or use Replace to swap a single juror.'
            );
        }

        $wanted = [];

        foreach ($body['jurors'] as $username) {
            if (is_string($username) && trim($username) !== '') {
                $wanted[\JuryTool\Domain\Entity\User::canonicaliseUsername($username)] = true;
            }
        }

        $existing = [];

        foreach ($round->getJurors() as $juror) {
            $existing[$juror->getUsername()] = $juror;

            if (!isset($wanted[$juror->getUsername()])) {
                $hasVotes = (int) $this->em->createQuery(
                    'SELECT COUNT(v.id) FROM ' . \JuryTool\Domain\Entity\Vote::class . ' v WHERE v.juror = :juror'
                )->setParameter('juror', $juror)->getSingleScalarResult() > 0;

                if ($hasVotes) {
                    $juror->setActive(false);
                } else {
                    $round->removeJuror($juror);
                    $this->em->remove($juror);
                }
            } else {
                $juror->setActive(true);
            }
        }

        foreach (array_keys($wanted) as $username) {
            if (!isset($existing[$username])) {
                $this->em->persist(new RoundJuror($round, $username));
            }
        }

        $this->em->flush();
    }

    private function find(string|int $id): Round
    {
        $round = $this->em->getRepository(Round::class)->find((int) $id);

        if ($round === null) {
            throw DomainException::notFound('Round');
        }

        return $round;
    }
}
