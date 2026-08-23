<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A juror's non-vote interaction with an image: skipping it for later, or
 * marking it a favourite.
 *
 * Kept apart from Vote because these carry no judgement — a skipped image
 * must still count as unjudged for quorum, and a favourite is a private
 * bookmark that must not influence the result.
 */
#[ORM\Entity]
#[ORM\Table(name: 'juror_image_action')]
#[ORM\UniqueConstraint(name: 'uniq_action_image_juror', columns: ['image_id', 'juror_id'])]
class JurorImageAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoundImage::class)]
    #[ORM\JoinColumn(name: 'image_id', nullable: false, onDelete: 'CASCADE')]
    private RoundImage $image;

    #[ORM\ManyToOne(targetEntity: RoundJuror::class)]
    #[ORM\JoinColumn(name: 'juror_id', nullable: false, onDelete: 'CASCADE')]
    private RoundJuror $juror;

    /**
     * Skipped images drop to the back of the queue rather than out of it,
     * so the juror can return to them once the easier calls are done.
     */
    #[ORM\Column(name: 'is_skipped', type: 'boolean')]
    private bool $isSkipped = false;

    #[ORM\Column(name: 'is_favorite', type: 'boolean')]
    private bool $isFavorite = false;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(RoundImage $image, RoundJuror $juror)
    {
        $this->image = $image;
        $this->juror = $juror;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImage(): RoundImage
    {
        return $this->image;
    }

    public function getJuror(): RoundJuror
    {
        return $this->juror;
    }

    public function isSkipped(): bool
    {
        return $this->isSkipped;
    }

    public function setSkipped(bool $skipped): void
    {
        $this->isSkipped = $skipped;
        $this->touch();
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setFavorite(bool $favorite): void
    {
        $this->isFavorite = $favorite;
        $this->touch();
    }

    /** True once neither flag is set, so the row can be discarded. */
    public function isEmpty(): bool
    {
        return !$this->isSkipped && !$this->isFavorite;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
