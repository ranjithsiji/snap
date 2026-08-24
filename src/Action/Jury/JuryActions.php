<?php

declare(strict_types=1);

namespace JuryTool\Action\Jury;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\RoundState;
use JuryTool\Domain\Enum\VotingMethod;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Service\StatisticsService;
use JuryTool\Service\VotingService;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The juror's side of the tool: which rounds they are in, what to judge
 * next, and casting votes.
 */
class JuryActions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VotingService $voting,
        private readonly StatisticsService $statistics,
    ) {
    }

    /** Rounds this user is a juror in, newest first. */
    public function myRounds(Request $request, Response $response): Response
    {
        $user = $this->user($request);

        $jurorEntries = $this->em->getRepository(RoundJuror::class)->findBy([
            'user' => $user,
            'isActive' => true,
        ]);

        // A juror invited before their first login is matched by username
        // until VotingService binds the account.
        $byUsername = $this->em->getRepository(RoundJuror::class)->findBy([
            'username' => $user->getUsername(),
            'user' => null,
            'isActive' => true,
        ]);

        $rounds = [];

        foreach ([...$jurorEntries, ...$byUsername] as $entry) {
            $round = $entry->getRound();

            if ($round->getState() === RoundState::Draft) {
                continue;
            }

            $data = Presenter::round($round);

            // This juror's own progress in this round. Without it the
            // dashboard can say only that a round exists, not whether
            // there is work waiting in it — which is the reason to look.
            foreach ($this->statistics->jurorProgress($round) as $progress) {
                if ((int) $progress['id'] === (int) $entry->getId()) {
                    $data['myProgress'] = [
                        'voted' => $progress['votesCast'],
                        'expected' => $progress['expected'],
                        'remaining' => $progress['remaining'],
                        'percentComplete' => $progress['percentComplete'],
                    ];

                    break;
                }
            }

            $rounds[(int) $round->getId()] = $data;
        }

        krsort($rounds);

        return Json::write($response, ['rounds' => array_values($rounds)]);
    }

    /**
     * The juror's view of a round: the brief, their progress, and whether
     * there is anything left to judge.
     */
    public function round(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $round = $this->find($args['id']);
        $juror = $this->voting->resolveJuror($round, $user);

        $remaining = $this->voting->remainingCount($round, $user);
        $counts = $this->voting->galleryCounts($round, $user);

        $data = [
            'round' => Presenter::round($round),
            'remaining' => $remaining,
            'hasRemaining' => $remaining > 0,
            'counts' => $counts,
        ];

        // A juror who took over someone else's seat inherits their votes.
        // Say so plainly: those votes now carry this juror's name, and they
        // are responsible for any they disagree with.
        if ($juror->wasReplaced()) {
            $inherited = $counts['selected'] + $counts['rejected'];

            $data['handover'] = [
                'replacedUsername' => $juror->getReplacedUsername(),
                'replacedAt' => $juror->getReplacedAt()?->format(\DateTimeInterface::ATOM),
                'inheritedVotes' => $inherited,
                'notice' => sprintf(
                    'You have taken over this round from %s. %d vote(s) they already cast are now '
                    . 'recorded under your name and count towards the quorum. Review them under '
                    . '"Edit previous votes" and change any you disagree with — the remaining %d '
                    . 'image(s) are still unjudged.',
                    $juror->getReplacedUsername(),
                    $inherited,
                    $counts['unrated'],
                ),
            ];
        }

        // Only surfaced when the round enables it, since a coordinator may
        // deliberately keep jurors blind to their own tallies.
        if ($round->showsOwnStatistics()) {
            $data['statistics'] = $this->statistics->ownStatistics($round, $juror);
        }

        return Json::write($response, $data);
    }

    /**
     * The next images to judge.
     *
     * Yes/no and rating rounds fetch one at a time; ranking rounds ask for
     * the whole set, which the juror then orders.
     */
    public function queue(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $round = $this->find($args['id']);

        $query = $request->getQueryParams();

        $requested = (int) ($query['limit'] ?? 1);
        $limit = $round->getVotingMethod() === VotingMethod::RankOrder
            ? min(200, max(1, $requested ?: 200))
            : min(20, max(1, $requested));

        $images = $this->voting->nextImagesFor($round, $user, $limit);

        // Jurors on slow connections can ask for smaller thumbnails; the
        // images still come straight from Wikimedia, just at a lower width.
        $lowBandwidth = filter_var($query['lowBandwidth'] ?? false, FILTER_VALIDATE_BOOL);

        return Json::write($response, [
            'images' => array_map(
                static fn (RoundImage $i): array => Presenter::imageForJuror($i, $lowBandwidth),
                $images,
            ),
            'exhausted' => $images === [],
        ]);
    }

    /** Records a vote on one image. */
    public function vote(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $image = $this->em->getRepository(RoundImage::class)->find((int) $args['imageId']);

        if ($image === null) {
            throw DomainException::notFound('Image');
        }

        $body = Json::body($request);

        if (!array_key_exists('score', $body)) {
            throw DomainException::badRequest("Field 'score' is required.");
        }

        $vote = $this->voting->castVote(
            $image,
            $user,
            Json::int($body, 'score'),
            Json::optionalString($body, 'comment'),
        );

        $round = $image->getRound();

        $data = [
            'vote' => [
                'imageId' => $image->getId(),
                'score' => $vote->getScore(),
                'comment' => $vote->getComment(),
            ],
        ];

        if ($round->showsOwnStatistics()) {
            $data['statistics'] = $this->statistics->ownStatistics($round, $vote->getJuror());
        }

        return Json::write($response, $data);
    }

    /**
     * Paged gallery of the round's images, filtered by how this juror
     * voted, for the grid view's Unrated / Selected / Rejected tabs.
     */
    public function gallery(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $round = $this->find($args['id']);
        $query = $request->getQueryParams();

        $filter = (string) ($query['filter'] ?? 'unrated');

        if (!in_array($filter, ['unrated', 'selected', 'rejected', 'favorites', 'all'], true)) {
            throw DomainException::badRequest('Unknown gallery filter.');
        }

        $limit = min(120, max(12, (int) ($query['limit'] ?? 60)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->voting->gallery($round, $user, $filter, $limit, $offset);

        return Json::write($response, [
            'images' => array_map(
                static fn (array $row): array => Presenter::imageForJuror($row['image']) + [
                    'score' => $row['score'],
                    'isFavorite' => $row['isFavorite'],
                ],
                $page['images'],
            ),
            'total' => $page['total'],
            'counts' => $this->voting->galleryCounts($round, $user),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Defers an image to the end of the queue, or restores it. */
    public function skip(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $image = $this->findImage($args['imageId']);

        $this->voting->setSkipped(
            $image,
            $user,
            Json::bool(Json::body($request), 'skipped', true),
        );

        return Json::write($response, ['ok' => true]);
    }

    /** Marks or unmarks one of this juror's favourites. */
    public function favorite(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $image = $this->findImage($args['imageId']);

        $favorite = Json::bool(Json::body($request), 'favorite', true);
        $this->voting->setFavorite($image, $user, $favorite);

        return Json::write($response, ['ok' => true, 'isFavorite' => $favorite]);
    }

    /** Votes this juror already cast, so they can revise them. */
    public function previousVotes(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $round = $this->find($args['id']);
        $query = $request->getQueryParams();

        $votes = $this->voting->previousVotes(
            $round,
            $user,
            min(200, max(1, (int) ($query['limit'] ?? 100))),
            max(0, (int) ($query['offset'] ?? 0)),
        );

        return Json::write($response, [
            'votes' => array_map(
                static fn ($vote): array => Presenter::imageForJuror($vote->getImage()) + [
                    'score' => $vote->getScore(),
                    'comment' => $vote->getComment(),
                    'updatedAt' => $vote->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $votes,
            ),
        ]);
    }

    /** Submits a full ordering for a ranking round. */
    public function rank(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $round = $this->find($args['id']);
        $body = Json::body($request);

        $order = $body['order'] ?? null;

        if (!is_array($order) || $order === []) {
            throw DomainException::badRequest("Field 'order' must be a non-empty list of image ids.");
        }

        $votes = $this->voting->submitRanking(
            $round,
            $user,
            array_map(static fn ($id): int => (int) $id, array_values($order)),
        );

        return Json::write($response, ['ranked' => count($votes)]);
    }

    private function user(Request $request): User
    {
        $user = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        if (!$user instanceof User) {
            throw DomainException::unauthorized();
        }

        return $user;
    }

    private function find(string|int $id): Round
    {
        $round = $this->em->getRepository(Round::class)->find((int) $id);

        if ($round === null) {
            throw DomainException::notFound('Round');
        }

        return $round;
    }

    private function findImage(string|int $id): RoundImage
    {
        $image = $this->em->getRepository(RoundImage::class)->find((int) $id);

        if ($image === null) {
            throw DomainException::notFound('Image');
        }

        return $image;
    }
}
