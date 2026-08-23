<?php

declare(strict_types=1);

namespace JuryTool\Action\Jury;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\ConflictOpinion;
use JuryTool\Domain\Entity\MeetingComment;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\User;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Service\ActivityLogger;
use JuryTool\Service\MeetingService;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The final jury meeting.
 *
 * Deliberately asynchronous: the panel typically talks over a video call
 * and records the outcome here, so these are ordinary request/response
 * endpoints with no realtime channel.
 */
class MeetingActions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MeetingService $meetings,
        private readonly ActivityLogger $log,
    ) {
    }

    /** The meeting: shared ranking, general discussion, and permissions. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);

        if (!$this->meetings->canParticipate($meeting, $user)) {
            throw DomainException::forbidden('You are not part of this jury meeting.');
        }

        return Json::write($response, [
            'round' => Presenter::round($meeting),
            'consensus' => $this->meetings->consensus($meeting),
            'revision' => $this->meetings->revision($meeting),
            'discussion' => $this->meetings->comments($meeting, 'general'),
            'isFinalized' => $meeting->getState()->isFinal(),
            'canFinalize' => $user->hasRole(\JuryTool\Domain\Enum\UserRole::Organizer),
        ]);
    }

    /** Rewrites the shared ranking. */
    public function reorder(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);
        $body = Json::body($request);

        $order = $body['order'] ?? null;

        if (!is_array($order) || $order === []) {
            throw DomainException::badRequest("Field 'order' must be a non-empty list of image ids.");
        }

        $this->meetings->reorder(
            $meeting,
            $user,
            array_map(static fn ($id): int => (int) $id, array_values($order)),
            Json::optionalString($body, 'revision'),
        );

        return Json::write($response, [
            'consensus' => $this->meetings->consensus($meeting),
            'revision' => $this->meetings->revision($meeting),
        ]);
    }

    /**
     * Every juror's proposed ranking side by side, with disagreements
     * flagged. This is the main meeting screen.
     */
    public function proposals(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);

        if (!$this->meetings->canParticipate($meeting, $user)) {
            throw DomainException::forbidden('You are not part of this jury meeting.');
        }

        return Json::write($response, [
            'round' => Presenter::round($meeting),
            'images' => $this->meetings->proposalMatrix($meeting),
            'revision' => $this->meetings->revision($meeting),
            'isFinalized' => $meeting->getState()->isFinal(),
        ]);
    }

    /** Only the images the panel disagrees about, worst first. */
    public function conflicts(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);

        if (!$this->meetings->canParticipate($meeting, $user)) {
            throw DomainException::forbidden('You are not part of this jury meeting.');
        }

        return Json::write($response, ['conflicts' => $this->meetings->conflicts($meeting)]);
    }

    /** Records this juror's own proposed ordering. */
    public function propose(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);
        $body = Json::body($request);

        $order = $body['order'] ?? null;

        if (!is_array($order) || $order === []) {
            throw DomainException::badRequest("Field 'order' must be a non-empty list of image ids.");
        }

        $this->meetings->propose(
            $meeting,
            $user,
            array_map(static fn ($id): int => (int) $id, array_values($order)),
        );

        return Json::write($response, [
            'images' => $this->meetings->proposalMatrix($meeting),
            'revision' => $this->meetings->revision($meeting),
        ]);
    }

    /** States a view on a disputed image. */
    public function opine(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);
        $body = Json::body($request);

        $position = Json::int($body, 'suggestedPosition', 0);

        $opinion = $this->meetings->addOpinion(
            $meeting,
            $user,
            (int) $args['imageId'],
            Json::requireString($body, 'body'),
            $position > 0 ? $position : null,
            Json::optionalString($body, 'supports'),
        );

        return Json::write($response, [
            'opinion' => [
                'id' => $opinion->getId(),
                'author' => $opinion->getAuthorUsername(),
                'body' => $opinion->getBody(),
                'suggestedPosition' => $opinion->getSuggestedPosition(),
                'supports' => $opinion->getSupportsUsername(),
            ],
        ], 201);
    }

    /** Agrees or disagrees with someone's opinion. */
    public function endorse(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $opinion = $this->em->getRepository(ConflictOpinion::class)->find((int) $args['opinionId']);

        if ($opinion === null) {
            throw DomainException::notFound('Opinion');
        }

        $this->meetings->endorseOpinion(
            $opinion,
            $user,
            Json::int(Json::body($request), 'value', 1),
        );

        return Json::write($response, [
            'images' => $this->meetings->proposalMatrix($opinion->getRound()),
        ]);
    }

    /** Comments on one image. */
    public function imageComments(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);

        if (!$this->meetings->canParticipate($meeting, $user)) {
            throw DomainException::forbidden('You are not part of this jury meeting.');
        }

        return Json::write($response, [
            'comments' => $this->meetings->comments($meeting, 'image', (int) $args['imageId']),
        ]);
    }

    /** Posts a comment, on an image or to the general thread. */
    public function comment(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);
        $body = Json::body($request);

        $references = $body['referencedImages'] ?? [];

        $comment = $this->meetings->comment(
            $meeting,
            $user,
            Json::requireString($body, 'body'),
            isset($args['imageId']) ? (int) $args['imageId'] : null,
            is_array($references)
                ? array_map(static fn ($id): int => (int) $id, array_values($references))
                : [],
        );

        return Json::write($response, [
            'comment' => [
                'id' => $comment->getId(),
                'author' => $comment->getAuthorUsername(),
                'body' => $comment->getBody(),
                'imageId' => $comment->getImage()?->getId(),
                'referencedImages' => $comment->getReferencedImages(),
                'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], 201);
    }

    /** Lets an author correct their own comment. */
    public function editComment(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $comment = $this->em->getRepository(MeetingComment::class)->find((int) $args['commentId']);

        if ($comment === null) {
            throw DomainException::notFound('Comment');
        }

        $this->meetings->editComment(
            $comment,
            $user,
            Json::requireString(Json::body($request), 'body'),
        );

        return Json::write($response, ['ok' => true]);
    }

    /** Locks the agreed result. */
    public function finalize(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);

        $this->meetings->finalize($meeting, $user);

        $this->log->record(
            $user,
            'meeting.finalize',
            sprintf('Finalized the jury meeting "%s"', $meeting->getName()),
            'Round',
            $meeting->getId(),
            request: $request,
        );

        return Json::write($response, [
            'round' => Presenter::round($meeting),
            'isFinalized' => true,
        ]);
    }

    /** Reopens a finalized meeting for further changes. */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $meeting = $this->find($args['id']);

        $this->meetings->reopen($meeting, $user);

        $this->log->record(
            $user,
            'meeting.reopen',
            sprintf('Reopened the jury meeting "%s"', $meeting->getName()),
            'Round',
            $meeting->getId(),
            request: $request,
        );

        return Json::write($response, [
            'round' => Presenter::round($meeting),
            'isFinalized' => false,
        ]);
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
            throw DomainException::notFound('Meeting');
        }

        return $round;
    }
}
