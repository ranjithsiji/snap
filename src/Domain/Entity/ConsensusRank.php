<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One image's position in a final jury meeting's shared ranking.
 *
 * Unlike Vote, which records one juror's private judgement, this is a
 * single list the whole panel edits together. Every row remembers who last
 * moved it, so the meeting can see how the consensus was reached.
 */
#[ORM\Entity]
#[ORM\Table(name: 'consensus_rank')]
#[ORM\UniqueConstraint(name: 'uniq_consensus_image', columns: ['round_id', 'image_id'])]
#[ORM\Index(name: 'idx_consensus_position', columns: ['round_id', 'position'])]
class ConsensusRank
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

    /** 1-based position in the agreed order. */
    #[ORM\Column(type: 'integer')]
    private int $position;

    /**
     * Where this image sat when the meeting opened, carried over from the
     * source round's aggregate. Kept so the panel can see what it changed.
     */
    #[ORM\Column(name: 'initial_position', type: 'integer')]
    private int $initialPosition;

    #[ORM\Column(name: 'moved_by', type: 'string', length: 255, nullable: true)]
    private ?string $movedBy = null;

    #[ORM\Column(name: 'moved_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $movedAt = null;

    public function __construct(Round $round, RoundImage $image, int $position)
    {
        $this->round = $round;
        $this->image = $image;
        $this->position = $position;
        $this->initialPosition = $position;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getInitialPosition(): int
    {
        return $this->initialPosition;
    }

    /** How far this image has moved from where the meeting found it. */
    public function getDrift(): int
    {
        return $this->initialPosition - $this->position;
    }

    public function moveTo(int $position, User $movedBy): void
    {
        if ($position === $this->position) {
            return;
        }

        $this->position = $position;
        $this->movedBy = $movedBy->getUsername();
        $this->movedAt = new DateTimeImmutable();
    }

    /** Repositions without crediting anyone — used when renumbering a list. */
    public function renumber(int $position): void
    {
        $this->position = $position;
    }

    public function getMovedBy(): ?string
    {
        return $this->movedBy;
    }

    public function getMovedAt(): ?DateTimeImmutable
    {
        return $this->movedAt;
    }
}
