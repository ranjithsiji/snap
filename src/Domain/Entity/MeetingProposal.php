<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One juror's proposed position for one image in a final jury meeting.
 *
 * The meeting keeps every juror's proposal rather than a single shared
 * list, so that when two jurors rank the same photograph differently the
 * disagreement is visible and can be discussed, instead of one silently
 * overwriting the other.
 *
 * The agreed outcome lives in ConsensusRank; these are the inputs to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meeting_proposal')]
#[ORM\UniqueConstraint(name: 'uniq_proposal', columns: ['image_id', 'juror_id'])]
#[ORM\Index(name: 'idx_proposal_round', columns: ['round_id', 'position'])]
class MeetingProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Round::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    #[ORM\ManyToOne(targetEntity: RoundImage::class)]
    #[ORM\JoinColumn(name: 'image_id', nullable: false, onDelete: 'CASCADE')]
    private RoundImage $image;

    #[ORM\ManyToOne(targetEntity: RoundJuror::class)]
    #[ORM\JoinColumn(name: 'juror_id', nullable: false, onDelete: 'CASCADE')]
    private RoundJuror $juror;

    /** Copied so the record survives the seat being reassigned. */
    #[ORM\Column(name: 'juror_username', type: 'string', length: 255)]
    private string $jurorUsername;

    /** 1-based position this juror proposes for the image. */
    #[ORM\Column(type: 'integer')]
    private int $position;

    /** Why this juror placed it here — shown beside the proposal. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rationale = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Round $round, RoundImage $image, RoundJuror $juror, int $position)
    {
        $this->round = $round;
        $this->image = $image;
        $this->juror = $juror;
        $this->jurorUsername = $juror->getUsername();
        $this->position = $position;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getImage(): RoundImage
    {
        return $this->image;
    }

    public function getJuror(): RoundJuror
    {
        return $this->juror;
    }

    public function getJurorUsername(): string
    {
        return $this->jurorUsername;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getRationale(): ?string
    {
        return $this->rationale;
    }

    public function setRationale(?string $rationale): void
    {
        $this->rationale = $rationale !== null && trim($rationale) !== '' ? trim($rationale) : null;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
