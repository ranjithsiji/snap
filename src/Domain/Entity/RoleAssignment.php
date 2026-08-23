<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\UserRole;

/**
 * Grants a user a role over a particular thing.
 *
 * One table serves every level of the hierarchy, scoped by which column is
 * set:
 *
 *   Admin      no scope             — authority over the whole tool
 *   Lead       project              — runs one project
 *   Organizer  campaign             — helps run one campaign
 *   Jury       round                — judges one round (see also RoundJuror)
 *
 * Keeping grants scoped rather than global is what allows one person to
 * lead Wiki Loves Folklore while merely judging a round of Wiki Loves
 * Earth, without either role leaking into the other.
 */
#[ORM\Entity]
#[ORM\Table(name: 'role_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_role_scope', columns: ['user_id', 'role', 'project_id', 'campaign_id'])]
#[ORM\Index(name: 'idx_role_user', columns: ['user_id', 'role'])]
#[ORM\Index(name: 'idx_role_project', columns: ['project_id'])]
#[ORM\Index(name: 'idx_role_campaign', columns: ['campaign_id'])]
class RoleAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 32, enumType: UserRole::class)]
    private UserRole $role;

    /** Set for a Lead grant. */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** Set for an Organizer grant. */
    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', nullable: true, onDelete: 'CASCADE')]
    private ?Campaign $campaign = null;

    /** Who granted it, kept as a name so the record survives their deletion. */
    #[ORM\Column(name: 'granted_by', type: 'string', length: 255, nullable: true)]
    private ?string $grantedBy = null;

    #[ORM\Column(name: 'granted_at', type: 'datetime_immutable')]
    private DateTimeImmutable $grantedAt;

    public function __construct(User $user, UserRole $role, ?User $grantedBy = null)
    {
        $this->user = $user;
        $this->role = $role;
        $this->grantedBy = $grantedBy?->getUsername();
        $this->grantedAt = new DateTimeImmutable();
    }

    /** Grants someone the lead of a project. */
    public static function lead(User $user, Project $project, ?User $grantedBy = null): self
    {
        $assignment = new self($user, UserRole::Lead, $grantedBy);
        $assignment->project = $project;

        return $assignment;
    }

    /** Grants someone organizer rights over one campaign. */
    public static function organizer(User $user, Campaign $campaign, ?User $grantedBy = null): self
    {
        $assignment = new self($user, UserRole::Organizer, $grantedBy);
        $assignment->campaign = $campaign;
        // Recorded too, so "everything in this project" queries are a
        // single condition rather than a join through campaigns.
        $assignment->project = $campaign->getProject();

        return $assignment;
    }

    /** Grants tool-wide administrator rights. */
    public static function admin(User $user, ?User $grantedBy = null): self
    {
        return new self($user, UserRole::Admin, $grantedBy);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function getGrantedBy(): ?string
    {
        return $this->grantedBy;
    }

    public function getGrantedAt(): DateTimeImmutable
    {
        return $this->grantedAt;
    }

    /** Whether this grant covers the given project. */
    public function coversProject(?Project $project): bool
    {
        if ($this->role === UserRole::Admin) {
            return true;
        }

        return $project !== null
            && $this->project !== null
            && $this->project->getId() === $project->getId();
    }

    /** Whether this grant covers the given campaign. */
    public function coversCampaign(?Campaign $campaign): bool
    {
        if ($this->role === UserRole::Admin) {
            return true;
        }

        if ($campaign === null) {
            return false;
        }

        // A lead's authority reaches every campaign in their project.
        if ($this->role === UserRole::Lead) {
            return $this->coversProject($campaign->getProject());
        }

        return $this->campaign !== null
            && $this->campaign->getId() === $campaign->getId();
    }

    /** Human-readable description of what this grant covers. */
    public function scopeLabel(): string
    {
        return match (true) {
            $this->role === UserRole::Admin => 'the whole tool',
            $this->campaign !== null => 'the campaign ' . $this->campaign->getName(),
            $this->project !== null => 'the project ' . $this->project->getName(),
            default => 'nothing in particular',
        };
    }
}
