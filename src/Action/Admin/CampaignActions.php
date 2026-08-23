<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\CampaignParticipant;
use JuryTool\Domain\Enum\ParticipantRole;
use JuryTool\Domain\Enum\SourceType;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Entity\Project;
use JuryTool\Domain\Entity\RoleAssignment;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Service\AccessControl;
use JuryTool\Service\ActivityLogger;
use JuryTool\Service\ImportService;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Campaign CRUD, participant roles, and importing the master image pool.
 */
class CampaignActions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportService $import,
        private readonly ActivityLogger $log,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * Appoints an organizer to help run this campaign.
     *
     * The project's lead decides who helps them; the appointment is scoped
     * to this campaign alone.
     */
    public function appointOrganizer(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $campaign = $this->find($args['id']);

        if ($actor === null) {
            throw DomainException::unauthorized();
        }

        $this->access->requireLead($actor, $campaign->getProject());

        $username = User::canonicaliseUsername(
            Json::requireString(Json::body($request), 'username')
        );

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);

        if ($user === null) {
            // Appointing someone before their first login is normal; the
            // account binds to their Wikimedia identity when they arrive.
            $user = new User($username, UserRole::Jury);
            $this->em->persist($user);
            $this->em->flush();
        }

        $this->access->appointOrganizer($user, $campaign, $actor);

        if ($user->getRole()->level() < UserRole::Organizer->level()) {
            $user->setRole(UserRole::Organizer);
            $this->em->flush();
        }

        $this->log->record(
            $actor,
            'campaign.appoint_organizer',
            sprintf('Appointed %s to organize "%s"', $username, $campaign->getName()),
            'Campaign',
            $campaign->getId(),
            request: $request,
        );

        return Json::write($response, ['organizers' => $this->organizers($campaign)]);
    }

    /** Removes an organizer. Their account and other seats are untouched. */
    public function removeOrganizer(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $campaign = $this->find($args['id']);

        if ($actor === null) {
            throw DomainException::unauthorized();
        }

        $this->access->requireLead($actor, $campaign->getProject());

        $assignment = $this->em->getRepository(RoleAssignment::class)->findOneBy([
            'campaign' => $campaign,
            'role' => UserRole::Organizer,
            'user' => (int) $args['userId'],
        ]);

        if ($assignment === null) {
            throw DomainException::notFound('Organizer');
        }

        $former = $assignment->getUser();
        $username = $former->getUsername();

        $this->access->revoke($assignment);
        $this->access->syncBaselineRole($former);

        $this->log->record(
            $actor,
            'campaign.remove_organizer',
            sprintf('Removed %s as organizer of "%s"', $username, $campaign->getName()),
            'Campaign',
            $campaign->getId(),
            request: $request,
        );

        return Json::write($response, ['organizers' => $this->organizers($campaign)]);
    }

    /** @return list<array<string, mixed>> */
    private function organizers(Campaign $campaign): array
    {
        $assignments = $this->em->getRepository(RoleAssignment::class)->findBy([
            'campaign' => $campaign,
            'role' => UserRole::Organizer,
        ]);

        return array_map(
            static fn (RoleAssignment $a): array => [
                'userId' => $a->getUser()->getId(),
                'username' => $a->getUser()->getUsername(),
                'appointedBy' => $a->getGrantedBy(),
            ],
            $assignments,
        );
    }

    /** The signed-in user, for audit entries. */
    private function actor(Request $request): ?User
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        return $actor instanceof User ? $actor : null;
    }

    public function list(Request $request, Response $response): Response
    {
        $campaigns = $this->em->getRepository(Campaign::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return Json::write($response, [
            'campaigns' => array_map(
                static fn (Campaign $c): array => Presenter::campaign($c),
                $campaigns,
            ),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->find($args['id']);

        return Json::write($response, [
            'campaign' => Presenter::campaign($campaign, withRounds: true),
            'participants' => $this->participants($campaign),
            'organizers' => $this->organizers($campaign),
        ]);
    }

    /**
     * Creates a campaign and, unless told otherwise, immediately imports
     * its source into the master image pool.
     */
    public function create(Request $request, Response $response): Response
    {
        $actor = $this->actor($request);
        $body = Json::body($request);

        $project = $this->em->getRepository(Project::class)->find(Json::int($body, 'projectId'));

        if ($project === null) {
            throw DomainException::badRequest('A valid projectId is required.');
        }

        if ($actor === null) {
            throw DomainException::unauthorized();
        }

        // Campaigns are editions of a project, so its lead creates them.
        $this->access->requireLead($actor, $project);

        $name = Json::requireString($body, 'name');
        $slug = Json::optionalString($body, 'slug') ?? $this->slugify($name);

        if ($this->em->getRepository(Campaign::class)->findOneBy(['slug' => $slug]) !== null) {
            throw DomainException::badRequest("A campaign with the slug '$slug' already exists.");
        }

        $campaign = new Campaign($project, $name, $slug);
        $this->applySettings($campaign, $body);

        // A campaign no longer needs a source of its own: its rounds each
        // gather their own Commons category — Trees, Rivers — so the
        // campaign is a container for them rather than an image pool.
        $this->em->persist($campaign);
        $this->em->flush();

        $result = null;

        if (Json::bool($body, 'importNow', false) && $campaign->hasUsableSource()) {
            $result = $this->import->importCampaign($campaign)->toArray();
        }

        $this->log->record(
            $this->actor($request),
            'campaign.create',
            sprintf('Created campaign "%s"', $campaign->getName()),
            'Campaign',
            $campaign->getId(),
            $result,
            $request,
        );

        return Json::write($response, [
            'campaign' => Presenter::campaign($campaign),
            'import' => $result,
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->find($args['id']);
        $body = Json::body($request);

        if (($name = Json::optionalString($body, 'name')) !== null) {
            $campaign->setName($name);
        }

        $this->applySettings($campaign, $body);
        $this->em->flush();

        return Json::write($response, ['campaign' => Presenter::campaign($campaign)]);
    }

    /** Re-runs the import to pick up files added to the source since. */
    public function reimport(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->find($args['id']);
        $result = $this->import->importCampaign($campaign);

        $this->log->record(
            $this->actor($request),
            'campaign.import',
            sprintf(
                'Re-imported "%s": %d added, %d updated',
                $campaign->getName(),
                $result->added,
                $result->updated,
            ),
            'Campaign',
            $campaign->getId(),
            $result->toArray(),
            $request,
        );

        // withRounds, because the campaign screen replaces what it is
        // showing with this response. Without them the rounds it has
        // already rendered lose their data and the page breaks.
        return Json::write($response, [
            'campaign' => Presenter::campaign($campaign, withRounds: true),
            'import' => $result->toArray(),
        ]);
    }

    /** Replaces the campaign's participant roster for a given role. */
    public function setParticipants(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->find($args['id']);
        $body = Json::body($request);

        $role = ParticipantRole::tryFrom((string) ($body['role'] ?? ''));

        if ($role === null) {
            throw DomainException::badRequest('A valid participant role is required.');
        }

        $usernames = $body['usernames'] ?? [];

        if (!is_array($usernames)) {
            throw DomainException::badRequest("Field 'usernames' must be a list.");
        }

        foreach ($campaign->getParticipants() as $existing) {
            if ($existing->getRole() === $role) {
                $campaign->removeParticipant($existing);
                $this->em->remove($existing);
            }
        }

        // Flush the removals before inserting, or the unique constraint on
        // (campaign, username, role) trips on names present in both sets.
        $this->em->flush();

        foreach ($usernames as $username) {
            if (!is_string($username) || trim($username) === '') {
                continue;
            }

            $this->em->persist(new CampaignParticipant($campaign, $username, $role));
        }

        $this->em->flush();

        return Json::write($response, ['participants' => $this->participants($campaign)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->find($args['id']);

        $name = $campaign->getName();
        $id = $campaign->getId();
        $actorId = $this->actor($request)?->getId();

        $this->em->remove($campaign);
        $this->em->flush();

        // The rounds and images beneath this campaign are removed by the
        // database, not by Doctrine, so any that were loaded would still be
        // managed here and the log's flush would try to re-persist them
        // against a campaign that no longer exists.
        $this->em->clear();

        $this->log->record(
            $actorId === null ? null : $this->em->getRepository(User::class)->find($actorId),
            'campaign.delete',
            sprintf('Deleted campaign "%s" and everything beneath it', $name),
            'Campaign',
            $id,
            request: $request,
        );

        return Json::write($response, ['ok' => true]);
    }

    /** @param array<string, mixed> $body */
    private function applySettings(Campaign $campaign, array $body): void
    {
        if (array_key_exists('description', $body)) {
            $campaign->setDescription(Json::optionalString($body, 'description'));
        }

        if (array_key_exists('year', $body)) {
            $campaign->setYear(Json::int($body, 'year') ?: null);
        }

        if (array_key_exists('startsAt', $body)) {
            $campaign->setStartsAt(Json::date($body, 'startsAt'));
        }

        if (array_key_exists('endsAt', $body)) {
            $campaign->setEndsAt(Json::date($body, 'endsAt'));
        }

        if (array_key_exists('isClosed', $body)) {
            $campaign->setClosed(Json::bool($body, 'isClosed'));
        }

        if (array_key_exists('isArchived', $body)) {
            $campaign->setArchived(Json::bool($body, 'isArchived'));
        }

        if (array_key_exists('sourceType', $body)) {
            $type = SourceType::tryFrom((string) $body['sourceType']);

            if ($type === null || $type === SourceType::PreviousRound) {
                throw DomainException::badRequest('Invalid source type for a campaign.');
            }

            $campaign->setSourceType($type);
        }

        if (array_key_exists('sourceCategory', $body)) {
            $campaign->setSourceCategory(Json::optionalString($body, 'sourceCategory'));
        }

        if (array_key_exists('sourceUrl', $body)) {
            $campaign->setSourceUrl(Json::optionalString($body, 'sourceUrl'));
        }

        if (array_key_exists('sourceFileList', $body)) {
            $campaign->setSourceFileList(Json::optionalString($body, 'sourceFileList'));
        }
    }

    /** @return array<string, list<string>> */
    private function participants(Campaign $campaign): array
    {
        $byRole = [];

        foreach (ParticipantRole::cases() as $role) {
            $byRole[$role->value] = [];
        }

        foreach ($campaign->getParticipants() as $participant) {
            $byRole[$participant->getRole()->value][] = $participant->getUsername();
        }

        return $byRole;
    }

    private function find(string|int $id): Campaign
    {
        $campaign = $this->em->getRepository(Campaign::class)->find((int) $id);

        if ($campaign === null) {
            throw DomainException::notFound('Campaign');
        }

        return $campaign;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'campaign';
    }
}
