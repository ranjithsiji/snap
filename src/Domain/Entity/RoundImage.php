<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A campaign file entered into a specific round.
 *
 * Commons metadata lives on the referenced CampaignImage; this row records
 * only what is round-specific — whether the round's rules disqualified it,
 * and the votes cast on it. One row per (round, image), so votes never leak
 * between rounds even when the same file advances.
 */
#[ORM\Entity]
#[ORM\Table(name: 'round_image')]
#[ORM\UniqueConstraint(name: 'uniq_round_image', columns: ['round_id', 'campaign_image_id'])]
#[ORM\Index(name: 'idx_round_image_state', columns: ['round_id', 'is_disqualified'])]
class RoundImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Round::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    #[ORM\ManyToOne(targetEntity: CampaignImage::class)]
    #[ORM\JoinColumn(name: 'campaign_image_id', nullable: false, onDelete: 'CASCADE')]
    private CampaignImage $image;

    /**
     * Set when one of the round's file settings rejected this image.
     * Disqualified rows are kept rather than dropped so coordinators can
     * audit exactly what was excluded and why.
     */
    #[ORM\Column(name: 'is_disqualified', type: 'boolean')]
    private bool $isDisqualified = false;

    #[ORM\Column(name: 'disqualification_reason', type: 'string', length: 255, nullable: true)]
    private ?string $disqualificationReason = null;

    #[ORM\Column(name: 'added_at', type: 'datetime_immutable')]
    private DateTimeImmutable $addedAt;

    /** @var Collection<int, Vote> */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'image', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $votes;

    public function __construct(Round $round, CampaignImage $image)
    {
        $this->round = $round;
        $this->image = $image;
        $this->addedAt = new DateTimeImmutable();
        $this->votes = new ArrayCollection();
        $round->addImage($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getImage(): CampaignImage
    {
        return $this->image;
    }

    // Commons metadata is read through to the pooled image so callers and
    // serialisers do not need to know about the indirection.

    public function getCommonsPageId(): int
    {
        return $this->image->getCommonsPageId();
    }

    public function getTitle(): string
    {
        return $this->image->getTitle();
    }

    public function getDisplayName(): string
    {
        return $this->image->getDisplayName();
    }

    public function getFileUrl(): string
    {
        return $this->image->getFileUrl();
    }

    public function getDescriptionUrl(): ?string
    {
        return $this->image->getDescriptionUrl();
    }

    public function getThumbUrl(): ?string
    {
        return $this->image->getThumbUrl();
    }

    public function getWidth(): int
    {
        return $this->image->getWidth();
    }

    public function getHeight(): int
    {
        return $this->image->getHeight();
    }

    public function getPixelCount(): int
    {
        return $this->image->getPixelCount();
    }

    public function getUploader(): ?string
    {
        return $this->image->getUploader();
    }

    public function getUploadedAt(): ?DateTimeImmutable
    {
        return $this->image->getUploadedAt();
    }

    public function isDisqualified(): bool
    {
        return $this->isDisqualified;
    }

    public function getDisqualificationReason(): ?string
    {
        return $this->disqualificationReason;
    }

    public function disqualify(string $reason): void
    {
        $this->isDisqualified = true;
        $this->disqualificationReason = $reason;
    }

    public function requalify(): void
    {
        $this->isDisqualified = false;
        $this->disqualificationReason = null;
    }

    public function getAddedAt(): DateTimeImmutable
    {
        return $this->addedAt;
    }

    /** @return Collection<int, Vote> */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): void
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
        }
    }

    /** Whether the round's quorum has been met for this image. */
    public function hasReachedQuorum(): bool
    {
        return $this->votes->count() >= $this->round->effectiveQuorum();
    }
}
