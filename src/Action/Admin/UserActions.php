<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\OpinionEndorsement;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\Project;
use JuryTool\Domain\Entity\RoleAssignment;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Service\AccessControl;
use JuryTool\Service\ActivityLogger;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * User administration: roles, blocking, password resets. Administrators only.
 */
class UserActions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogger $log,
        private readonly AccessControl $access,
    ) {
    }

    /** All users, with a search filter for large installations. */
    public function list(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $search = trim((string) ($query['q'] ?? ''));

        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.username', 'ASC');

        if ($search !== '') {
            $qb->where('u.username LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (($role = trim((string) ($query['role'] ?? ''))) !== '') {
            $qb->andWhere('u.role = :role')->setParameter('role', $role);
        }

        $users = $qb->getQuery()->getResult();

        return Json::write($response, [
            'users' => array_map(
                fn (User $u): array => Presenter::user($u) + [
                    'email' => $u->getEmail(),
                    'isActive' => $u->isActive(),
                    'hasLocalPassword' => $u->hasLocalPassword(),
                    'createdAt' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'lastLoginAt' => $u->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                    'jurorSeats' => $this->seatCount($u),
                ],
                $users,
            ),
            'roles' => array_map(
                static fn (UserRole $r): array => ['value' => $r->value, 'label' => $r->label()],
                UserRole::cases(),
            ),
        ]);
    }

    /** Creates a local account, for people who cannot use Wikimedia OAuth. */
    public function create(Request $request, Response $response): Response
    {
        $actor = $this->actor($request);
        $body = Json::body($request);

        $username = User::canonicaliseUsername(Json::requireString($body, 'username'));

        if ($this->em->getRepository(User::class)->findOneBy(['username' => $username]) !== null) {
            throw DomainException::badRequest("A user named '$username' already exists.");
        }

        $role = UserRole::tryFrom((string) ($body['role'] ?? UserRole::Jury->value));

        if ($role === null) {
            throw DomainException::badRequest('A valid role is required.');
        }

        $password = Json::optionalString($body, 'password') ?? $this->generatePassword();

        $user = new User($username, $role);
        $user->setPassword($password);
        $user->setEmail(Json::optionalString($body, 'email'));

        $this->em->persist($user);
        $this->em->flush();

        $this->log->record(
            $actor,
            'user.create',
            sprintf('Created %s account "%s"', $role->value, $username),
            'User',
            $user->getId(),
            request: $request,
        );

        // The generated password is returned once, here, and never stored in
        // readable form — the administrator must pass it on now.
        return Json::write($response, [
            'user' => Presenter::user($user),
            'password' => $password,
        ], 201);
    }

    /** Changes a user's name or email. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);
        $body = Json::body($request);

        $changes = [];

        if (($email = Json::optionalString($body, 'email')) !== null || array_key_exists('email', $body)) {
            $user->setEmail($email);
            $changes[] = 'email';
        }

        if (($username = Json::optionalString($body, 'username')) !== null) {
            $canonical = User::canonicaliseUsername($username);
            $clash = $this->em->getRepository(User::class)->findOneBy(['username' => $canonical]);

            if ($clash !== null && $clash->getId() !== $user->getId()) {
                throw DomainException::badRequest("A user named '$canonical' already exists.");
            }

            if ($canonical !== $user->getUsername()) {
                $changes[] = sprintf('renamed from %s to %s', $user->getUsername(), $canonical);
                $user->rename($canonical);
            }
        }

        $this->em->flush();

        if ($changes !== []) {
            $this->log->record(
                $actor,
                'user.update',
                sprintf('Updated %s (%s)', $user->getUsername(), implode(', ', $changes)),
                'User',
                $user->getId(),
                request: $request,
            );
        }

        return Json::write($response, ['user' => Presenter::user($user)]);
    }

    /**
     * Changes a user's global role.
     *
     * Two safeguards: an administrator cannot demote themselves by accident,
     * and the last administrator cannot be demoted at all — either would
     * leave the installation with nobody able to grant access back.
     */
    public function setRole(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);

        $role = UserRole::tryFrom((string) (Json::body($request)['role'] ?? ''));

        if ($role === null) {
            throw DomainException::badRequest('A valid role is required.');
        }

        $previous = $user->getRole();

        if ($previous === $role) {
            return Json::write($response, ['user' => Presenter::user($user)]);
        }

        if ($previous === UserRole::Admin) {
            $this->assertNotLastAdministrator($actor, $user);
        }

        $user->setRole($role);
        $this->em->flush();

        $this->log->record(
            $actor,
            'user.role',
            sprintf(
                'Changed %s from %s to %s',
                $user->getUsername(),
                $previous->value,
                $role->value,
            ),
            'User',
            $user->getId(),
            ['from' => $previous->value, 'to' => $role->value],
            $request,
        );

        return Json::write($response, ['user' => Presenter::user($user)]);
    }

    /** Blocks or unblocks an account. */
    public function setActive(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);

        $active = Json::bool(Json::body($request), 'isActive', true);

        if (!$active) {
            if ($actor?->getId() === $user->getId()) {
                throw DomainException::badRequest('You cannot block your own account.');
            }

            if ($user->getRole() === UserRole::Admin) {
                $this->assertNotLastAdministrator($actor, $user);
            }
        }

        $user->setActive($active);
        $this->em->flush();

        $this->log->record(
            $actor,
            $active ? 'user.unblock' : 'user.block',
            sprintf('%s %s', $active ? 'Unblocked' : 'Blocked', $user->getUsername()),
            'User',
            $user->getId(),
            request: $request,
        );

        return Json::write($response, [
            'user' => Presenter::user($user) + ['isActive' => $user->isActive()],
        ]);
    }

    /**
     * Everything one user has been trusted with, and where.
     *
     * The users table can only show a single word — "Lead" — which says
     * nothing about *which* project, and a person may lead one project
     * while merely judging a round of another. This is the view that
     * answers that, and the one the grant controls act on.
     */
    public function roles(Request $request, Response $response, array $args): Response
    {
        $user = $this->find($args['id']);

        // Juror seats are not RoleAssignment rows — a juror is invited to a
        // round by username — so they are gathered separately. Without them
        // the answer to "what is this person part of" is incomplete.
        $seats = $this->em->createQuery(
            'SELECT j FROM ' . RoundJuror::class . ' j
             WHERE j.user = :user OR (j.username = :username AND j.user IS NULL)
             ORDER BY j.invitedAt DESC'
        )->setParameters([
            'user' => $user,
            'username' => $user->getUsername(),
        ])->getResult();

        return Json::write($response, [
            'user' => Presenter::user($user) + ['isActive' => $user->isActive()],
            'grants' => $this->access->roleHistory($user),
            'rounds' => array_map(
                static fn (RoundJuror $j): array => [
                    'jurorId' => $j->getId(),
                    'roundId' => $j->getRound()->getId(),
                    'roundName' => $j->getRound()->getName(),
                    'campaignName' => $j->getRound()->getCampaign()->getName(),
                    'state' => $j->getRound()->getState()->value,
                    'isActive' => $j->isActive(),
                ],
                $seats,
            ),
            // Offered so the dialog can present real choices rather than
            // asking for an id to be typed.
            'projects' => array_map(
                static fn (Project $p): array => ['id' => $p->getId(), 'name' => $p->getName()],
                $this->em->getRepository(Project::class)->findBy([], ['name' => 'ASC']),
            ),
            'campaigns' => array_map(
                static fn (Campaign $c): array => [
                    'id' => $c->getId(),
                    'name' => $c->getName(),
                    'projectName' => $c->getProject()->getName(),
                ],
                $this->em->getRepository(Campaign::class)->findBy([], ['name' => 'ASC']),
            ),
        ]);
    }

    /**
     * Grants a scoped role: lead of a project, or organizer of a campaign.
     *
     * Administrator is deliberately not grantable here — it is the one role
     * with no scope, and it is set through the role column so the existing
     * last-administrator guard still applies.
     */
    public function grantRole(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);
        $body = Json::body($request);

        $role = UserRole::tryFrom((string) ($body['role'] ?? ''));

        if ($role === UserRole::Lead) {
            $project = $this->em->getRepository(Project::class)->find(Json::int($body, 'projectId'));

            if ($project === null) {
                throw DomainException::notFound('Project');
            }

            $this->access->appointLead($user, $project, $actor);
            $scope = $project->getName();
        } elseif ($role === UserRole::Organizer) {
            $campaign = $this->em->getRepository(Campaign::class)->find(Json::int($body, 'campaignId'));

            if ($campaign === null) {
                throw DomainException::notFound('Campaign');
            }

            $this->access->appointOrganizer($user, $campaign, $actor);
            $scope = $campaign->getName();
        } else {
            throw DomainException::badRequest(
                'Only lead and organizer are granted here; both need a project or campaign.'
            );
        }

        // The role column is a summary of the grants, so it follows them
        // rather than being set by hand.
        $this->access->syncBaselineRole($user);

        $this->log->record(
            $actor,
            'user.role_grant',
            sprintf('Made %s %s of %s', $user->getUsername(), $role->value, $scope),
            'User',
            $user->getId(),
            ['role' => $role->value, 'scope' => $scope],
            $request,
        );

        return Json::write($response, [
            'grants' => $this->access->roleHistory($user),
            'user' => Presenter::user($user),
        ]);
    }

    /** Withdraws one scoped grant. */
    public function revokeRole(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);

        $assignment = $this->em->getRepository(RoleAssignment::class)->find((int) $args['grantId']);

        if ($assignment === null || $assignment->getUser()->getId() !== $user->getId()) {
            throw DomainException::notFound('Role assignment');
        }

        // Removing the last administrator here would lock everyone out just
        // as surely as blocking them does.
        if ($assignment->getRole() === UserRole::Admin) {
            $this->assertNotLastAdministrator($actor, $user);
        }

        $scope = $assignment->scopeLabel();
        $role = $assignment->getRole();

        $this->access->revoke($assignment);
        $this->access->syncBaselineRole($user);

        $this->log->record(
            $actor,
            'user.role_revoke',
            sprintf('Removed %s as %s of %s', $user->getUsername(), $role->value, $scope),
            'User',
            $user->getId(),
            ['role' => $role->value, 'scope' => $scope],
            $request,
        );

        return Json::write($response, [
            'grants' => $this->access->roleHistory($user),
            'user' => Presenter::user($user),
        ]);
    }

    /**
     * Removes an account.
     *
     * Votes survive: a juror's seat holds their username as plain text and
     * the account link is nullable, so deleting the account leaves the
     * votes, their author's name and the tallies intact. Activity log
     * entries and meeting comments keep their text and lose only the link.
     *
     * Endorsements do not survive — they cascade — so an account carrying
     * any is refused rather than quietly altering a decided meeting.
     * Blocking is the way to retire such a juror.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);

        if ($actor?->getId() === $user->getId()) {
            throw DomainException::badRequest('You cannot delete your own account.');
        }

        if ($user->getRole() === UserRole::Admin) {
            $this->assertNotLastAdministrator($actor, $user);
        }

        $endorsements = (int) $this->em->createQuery(
            'SELECT COUNT(e.id) FROM ' . OpinionEndorsement::class . ' e WHERE e.juror = :user'
        )->setParameter('user', $user)->getSingleScalarResult();

        if ($endorsements > 0) {
            throw DomainException::badRequest(sprintf(
                'This user has endorsed %d meeting opinion(s), which would be removed with the '
                . 'account and would change a recorded outcome. Block the account instead.',
                $endorsements,
            ));
        }

        $username = $user->getUsername();
        $id = $user->getId();

        // Recorded before the delete, so the entry can still name the actor
        // and does not reference a row that is about to disappear.
        $this->log->record(
            $actor,
            'user.delete',
            sprintf('Deleted the account %s', $username),
            'User',
            $id,
            request: $request,
        );

        $this->em->remove($user);
        $this->em->flush();

        return Json::write($response, ['ok' => true, 'username' => $username]);
    }

    /**
     * Sets a new password for a local account.
     *
     * Returns the password once so the administrator can hand it over; it is
     * stored only as a hash.
     */
    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor($request);
        $user = $this->find($args['id']);

        $password = Json::optionalString(Json::body($request), 'password') ?? $this->generatePassword();

        if (mb_strlen($password) < 10) {
            throw DomainException::badRequest('Password must be at least 10 characters.');
        }

        $user->setPassword($password);
        $this->em->flush();

        $this->log->record(
            $actor,
            'user.password_reset',
            sprintf('Reset the password for %s', $user->getUsername()),
            'User',
            $user->getId(),
            request: $request,
        );

        return Json::write($response, ['password' => $password]);
    }

    /** The audit feed. */
    public function activity(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();

        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->log->recent(
            $limit,
            $offset,
            Json::optionalString($query, 'action'),
            Json::optionalString($query, 'actor'),
        );

        return Json::write($response, $page + [
            'actions' => $this->log->knownActions(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Headline counts for the dashboard landing page. */
    public function overview(Request $request, Response $response): Response
    {
        $byRole = [];

        foreach (UserRole::cases() as $role) {
            $byRole[$role->value] = (int) $this->em->createQuery(
                'SELECT COUNT(u.id) FROM ' . User::class . ' u WHERE u.role = :role'
            )->setParameter('role', $role->value)->getSingleScalarResult();
        }

        $blocked = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM ' . User::class . ' u WHERE u.isActive = false'
        )->getSingleScalarResult();

        $campaigns = (int) $this->em->createQuery(
            'SELECT COUNT(c.id) FROM ' . \JuryTool\Domain\Entity\Campaign::class . ' c'
        )->getSingleScalarResult();

        $rounds = (int) $this->em->createQuery(
            'SELECT COUNT(r.id) FROM ' . \JuryTool\Domain\Entity\Round::class . ' r'
        )->getSingleScalarResult();

        $images = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM ' . \JuryTool\Domain\Entity\CampaignImage::class . ' i'
        )->getSingleScalarResult();

        $votes = (int) $this->em->createQuery(
            'SELECT COUNT(v.id) FROM ' . \JuryTool\Domain\Entity\Vote::class . ' v'
        )->getSingleScalarResult();

        return Json::write($response, [
            'users' => array_sum($byRole),
            'usersByRole' => $byRole,
            'blockedUsers' => $blocked,
            'campaigns' => $campaigns,
            'rounds' => $rounds,
            'images' => $images,
            'votes' => $votes,
            'recentActivity' => $this->log->recent(10)['entries'],
        ]);
    }

    /**
     * Refuses to leave the installation without a usable administrator.
     */
    private function assertNotLastAdministrator(?User $actor, User $target): void
    {
        if ($actor !== null && $actor->getId() === $target->getId()) {
            throw DomainException::badRequest(
                'You cannot remove your own administrator access.'
            );
        }

        $remaining = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM ' . User::class . ' u
             WHERE u.role = :role AND u.isActive = true AND u.id <> :id'
        )->setParameters([
            'role' => UserRole::Admin->value,
            'id' => $target->getId(),
        ])->getSingleScalarResult();

        if ($remaining === 0) {
            throw DomainException::badRequest(
                'This is the only active administrator; promote someone else first.'
            );
        }
    }

    /** How many round juror seats this user currently holds. */
    private function seatCount(User $user): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(j.id) FROM ' . RoundJuror::class . ' j
             WHERE j.user = :user AND j.isActive = true'
        )->setParameter('user', $user)->getSingleScalarResult();
    }

    /** A readable but unguessable password to hand to a new user. */
    private function generatePassword(): string
    {
        // Ambiguous characters are left out so the password survives being
        // read aloud or copied by hand.
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        return $actor instanceof User ? $actor : null;
    }

    private function find(string|int $id): User
    {
        $user = $this->em->getRepository(User::class)->find((int) $id);

        if ($user === null) {
            throw DomainException::notFound('User');
        }

        return $user;
    }
}
