<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\UserRole;

/**
 * A person who can log in.
 *
 * Two login paths converge here. Wikimedia OAuth users are identified by
 * their Commons username and carry a centralAuthId; local fallback accounts
 * (for admins and for running the tool without an OAuth consumer) carry a
 * password hash instead. A row may have both, which lets an admin created
 * locally later bind their Commons identity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_user_central_auth', columns: ['central_auth_id'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Wikimedia username, canonical form (underscores normalised to spaces). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    /** Wikimedia global account id, present once the user has logged in via OAuth. */
    #[ORM\Column(name: 'central_auth_id', type: 'integer', nullable: true)]
    private ?int $centralAuthId = null;

    /** Only set for local fallback accounts. */
    #[ORM\Column(name: 'password_hash', type: 'string', length: 255, nullable: true)]
    private ?string $passwordHash = null;

    /**
     * The user's baseline role.
     *
     * Real authority comes from scoped RoleAssignment rows — leading a
     * project, organizing a campaign. This column records the highest
     * level they have been trusted with anywhere, which is what listings
     * and the navigation menu key off.
     */
    #[ORM\Column(type: 'string', length: 32, enumType: UserRole::class)]
    private UserRole $role = UserRole::Jury;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_login_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    public function __construct(string $username, UserRole $role = UserRole::Jury)
    {
        $this->username = self::canonicaliseUsername($username);
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Commons usernames treat underscores and spaces as equivalent and
     * uppercase the first letter. Normalising on the way in means a juror
     * added as "jane_doe" matches the "Jane Doe" who logs in.
     */
    public static function canonicaliseUsername(string $raw): string
    {
        $name = trim(str_replace('_', ' ', $raw));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Renames the account.
     *
     * Round invitations are keyed by username, so a rename does not follow
     * them automatically — the caller is responsible for any seats that
     * should move with it.
     */
    public function rename(string $username): void
    {
        $this->username = self::canonicaliseUsername($username);
    }

    public function getCentralAuthId(): ?int
    {
        return $this->centralAuthId;
    }

    public function bindWikimediaIdentity(int $centralAuthId): void
    {
        $this->centralAuthId = $centralAuthId;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): void
    {
        $this->role = $role;
    }

    public function hasRole(UserRole $required): bool
    {
        return $this->role->covers($required);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function setPassword(string $plain): void
    {
        $this->passwordHash = password_hash($plain, PASSWORD_DEFAULT);
    }

    public function verifyPassword(string $plain): bool
    {
        if ($this->passwordHash === null) {
            return false;
        }

        return password_verify($plain, $this->passwordHash);
    }

    public function hasLocalPassword(): bool
    {
        return $this->passwordHash !== null;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new DateTimeImmutable();
    }
}
