<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\UserRole;

/**
 * A contest family, such as "Wiki Loves Folklore" or "Wiki Loves Earth".
 *
 * Created by an admin, who appoints a lead to run it. The lead then
 * creates the project's campaigns — its yearly or regional editions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project')]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** URL-safe identifier, e.g. "wiki-loves-folklore". */
    #[ORM\Column(type: 'string', length: 128, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** The project's page on Commons or Meta, for jurors wanting context. */
    #[ORM\Column(name: 'homepage_url', type: 'string', length: 1024, nullable: true)]
    private ?string $homepageUrl = null;

    #[ORM\Column(name: 'is_archived', type: 'boolean')]
    private bool $isArchived = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Grants scoped to this project, including its leads.
     *
     * Leads are RoleAssignment rows rather than a column of their own, so
     * one mechanism covers every level of the hierarchy and a lead can be
     * replaced between editions without touching the project itself.
     *
     * @var Collection<int, RoleAssignment>
     */
    #[ORM\OneToMany(
        targetEntity: RoleAssignment::class,
        mappedBy: 'project',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $roleAssignments;

    /** @var Collection<int, Campaign> */
    #[ORM\OneToMany(targetEntity: Campaign::class, mappedBy: 'project')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $campaigns;

    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new DateTimeImmutable();
        $this->roleAssignments = new ArrayCollection();
        $this->campaigns = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getHomepageUrl(): ?string
    {
        return $this->homepageUrl;
    }

    public function setHomepageUrl(?string $url): void
    {
        $this->homepageUrl = $url !== null && trim($url) !== '' ? trim($url) : null;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setArchived(bool $archived): void
    {
        $this->isArchived = $archived;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, RoleAssignment> */
    public function getRoleAssignments(): Collection
    {
        return $this->roleAssignments;
    }

    /** Grants of the lead role over this project. */
    public function getLeads(): Collection
    {
        return $this->roleAssignments->filter(
            static fn (RoleAssignment $a): bool => $a->getRole() === UserRole::Lead
        );
    }

    /** Whether this user leads the project. */
    public function isLedBy(User $user): bool
    {
        foreach ($this->getLeads() as $lead) {
            if ($lead->getUser()->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function leadUsernames(): array
    {
        return array_values(
            $this->getLeads()->map(
                static fn (RoleAssignment $a): string => $a->getUser()->getUsername()
            )->toArray()
        );
    }

    /** @return Collection<int, Campaign> */
    public function getCampaigns(): Collection
    {
        return $this->campaigns;
    }
}
