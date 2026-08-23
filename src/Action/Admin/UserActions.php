<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Middleware\AuthenticationMiddleware;
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

        $role = UserRole::tryFrom((string) ($body['role'] ?? UserRole::Juror->value));

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

        if ($previous === UserRole::Administrator) {
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

            if ($user->getRole() === UserRole::Administrator) {
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
            'role' => UserRole::Administrator->value,
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
