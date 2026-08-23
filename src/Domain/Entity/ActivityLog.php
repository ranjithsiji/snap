<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audit record of something a user did.
 *
 * Deliberately append-only and denormalised: the actor's username is copied
 * in rather than joined, so the log still reads correctly after an account
 * is renamed or removed. Entries are never edited.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(name: 'idx_activity_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_activity_actor', columns: ['actor_username'])]
#[ORM\Index(name: 'idx_activity_action', columns: ['action'])]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Null for actions taken by the system, such as a scheduled import. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(name: 'actor_username', type: 'string', length: 255)]
    private string $actorUsername;

    /** Dotted action name, e.g. "round.activate" or "user.block". */
    #[ORM\Column(type: 'string', length: 64)]
    private string $action;

    /** Human-readable sentence describing what happened. */
    #[ORM\Column(type: 'string', length: 512)]
    private string $summary;

    /** Entity type and id the action concerned, when it concerned one. */
    #[ORM\Column(name: 'subject_type', type: 'string', length: 64, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(name: 'subject_id', type: 'integer', nullable: true)]
    private ?int $subjectId = null;

    /** Extra structured detail, kept as JSON so the shape can vary by action. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(?User $actor, string $action, string $summary)
    {
        $this->actor = $actor;
        $this->actorUsername = $actor?->getUsername() ?? 'system';
        $this->action = $action;
        $this->summary = $summary;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorUsername(): string
    {
        return $this->actorUsername;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function setSubject(string $type, ?int $id): void
    {
        $this->subjectType = $type;
        $this->subjectId = $id;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): void
    {
        $this->context = $context;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ip): void
    {
        $this->ipAddress = $ip;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
