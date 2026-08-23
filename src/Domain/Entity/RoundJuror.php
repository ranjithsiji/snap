<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A juror invited to a round.
 *
 * Coordinators add jurors by Wikimedia username, often before that person
 * has ever logged in, so username is authoritative and the User link is
 * filled in lazily on first login. Keeping both means an invitation
 * survives even if the account never materialises.
 */
#[ORM\Entity]
#[ORM\Table(name: 'round_juror')]
#[ORM\UniqueConstraint(name: 'uniq_round_juror', columns: ['round_id', 'username'])]
#[ORM\Index(name: 'idx_juror_username', columns: ['username'])]
class RoundJuror
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Round::class, inversedBy: 'jurors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    /** Canonical Wikimedia username. Set at invitation time. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    /** Resolved once this juror logs in for the first time. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /**
     * Inactive jurors keep their existing votes but are excluded from quorum
     * maths and can no longer vote — used when a juror withdraws mid-round.
     */
    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'invited_at', type: 'datetime_immutable')]
    private DateTimeImmutable $invitedAt;

    /**
     * Username this seat was taken over from, when an admin replaced an
     * inactive juror. The votes moved across with the seat, so this records
     * who actually cast them.
     */
    #[ORM\Column(name: 'replaced_username', type: 'string', length: 255, nullable: true)]
    private ?string $replacedUsername = null;

    #[ORM\Column(name: 'replaced_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $replacedAt = null;

    public function __construct(Round $round, string $username)
    {
        $this->round = $round;
        $this->username = User::canonicaliseUsername($username);
        $this->invitedAt = new DateTimeImmutable();
        $round->addJuror($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function linkUser(User $user): void
    {
        $this->user = $user;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getInvitedAt(): DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getReplacedUsername(): ?string
    {
        return $this->replacedUsername;
    }

    public function getReplacedAt(): ?DateTimeImmutable
    {
        return $this->replacedAt;
    }

    public function wasReplaced(): bool
    {
        return $this->replacedUsername !== null;
    }

    /**
     * Hands this seat to another user, keeping the votes already cast on it.
     *
     * The seat is the unit of work — quorum counts a seat's votes once —
     * so transferring rather than deleting keeps every image's tally
     * intact. The incoming juror inherits the votes and can revise any of
     * them, and the previous holder is recorded here so the change is
     * never silent.
     */
    public function replaceWith(string $username): void
    {
        $canonical = User::canonicaliseUsername($username);

        if ($canonical === $this->username) {
            return;
        }

        // Only the first handover is kept; chaining replacements would lose
        // the person who actually cast the original votes.
        $this->replacedUsername ??= $this->username;
        $this->replacedAt = new DateTimeImmutable();

        $this->username = $canonical;
        // Cleared so the next login by the new username binds the account.
        $this->user = null;
        $this->isActive = true;
    }
}
