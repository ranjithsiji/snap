<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Support\DomainException;

/**
 * Resolves identities from either login path onto a single User record.
 */
class AuthService
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly array $settings,
    ) {
    }

    public function localLoginEnabled(): bool
    {
        return (bool) $this->settings['local_login_enabled'];
    }

    /**
     * Authenticates a local fallback account.
     *
     * @throws DomainException when the credentials do not match.
     */
    public function authenticateLocal(string $username, string $password): User
    {
        if (!$this->localLoginEnabled()) {
            throw DomainException::forbidden('Local login is disabled on this server.');
        }

        $user = $this->em->getRepository(User::class)->findOneBy([
            'username' => User::canonicaliseUsername($username),
        ]);

        // Verify even when the user is missing, so a wrong username and a
        // wrong password take the same time to reject.
        $valid = $user?->verifyPassword($password) ?? false;

        if ($user === null || !$valid) {
            if ($user === null) {
                password_verify($password, '$2y$12$' . str_repeat('.', 53));
            }

            throw DomainException::unauthorized('Incorrect username or password.');
        }

        if (!$user->isActive()) {
            throw DomainException::forbidden('This account has been deactivated.');
        }

        $user->recordLogin();
        $this->em->flush();

        return $user;
    }

    /**
     * Finds or creates the User behind a Wikimedia identity.
     *
     * A juror invited by username before ever logging in already has a
     * RoundJuror row; first login binds it to the account created here.
     */
    public function resolveWikimediaUser(string $username, ?int $centralAuthId): User
    {
        $canonical = User::canonicaliseUsername($username);
        $repository = $this->em->getRepository(User::class);

        $user = null;

        // Prefer the global id: it survives a Wikimedia rename, whereas the
        // username does not.
        if ($centralAuthId !== null) {
            $user = $repository->findOneBy(['centralAuthId' => $centralAuthId]);
        }

        $user ??= $repository->findOneBy(['username' => $canonical]);

        if ($user === null) {
            $user = new User($canonical, $this->defaultRoleFor($canonical));
            $this->em->persist($user);
        }

        if ($centralAuthId !== null && $user->getCentralAuthId() === null) {
            $user->bindWikimediaIdentity($centralAuthId);
        }

        if (!$user->isActive()) {
            throw DomainException::forbidden('This account has been deactivated.');
        }

        $user->recordLogin();
        $this->em->flush();

        $this->linkPendingInvitations($user);

        return $user;
    }

    /**
     * The first account to exist becomes an admin, so a fresh deployment is
     * usable without seeding one by hand. Everyone else starts as a juror.
     */
    private function defaultRoleFor(string $username): UserRole
    {
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM ' . User::class . ' u'
        )->getSingleScalarResult();

        return $count === 0 ? UserRole::Administrator : UserRole::Juror;
    }

    /**
     * Attaches this user to any round invitations issued to their username
     * before the account existed.
     */
    private function linkPendingInvitations(User $user): void
    {
        $pending = $this->em->getRepository(RoundJuror::class)->findBy([
            'username' => $user->getUsername(),
            'user' => null,
        ]);

        if ($pending === []) {
            return;
        }

        foreach ($pending as $juror) {
            $juror->linkUser($user);
        }

        $this->em->flush();
    }

    /** Loads the user a verified token refers to, if they still exist. */
    public function userFromClaims(array $claims): ?User
    {
        $id = $claims['sub'] ?? null;

        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }

        $user = $this->em->getRepository(User::class)->find((int) $id);

        return $user?->isActive() === true ? $user : null;
    }
}
