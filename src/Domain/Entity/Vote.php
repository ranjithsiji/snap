<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One juror's judgement of one image.
 *
 * A single score column serves all three voting methods: 0/1 for yes/no,
 * 1..maxRating for rating, and a 1-based position for rank order. The
 * round's votingMethod tells you how to read it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vote')]
#[ORM\UniqueConstraint(name: 'uniq_vote_image_juror', columns: ['image_id', 'juror_id'])]
#[ORM\Index(name: 'idx_vote_juror', columns: ['juror_id'])]
class Vote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoundImage::class, inversedBy: 'votes')]
    #[ORM\JoinColumn(name: 'image_id', nullable: false, onDelete: 'CASCADE')]
    private RoundImage $image;

    #[ORM\ManyToOne(targetEntity: RoundJuror::class)]
    #[ORM\JoinColumn(name: 'juror_id', nullable: false, onDelete: 'CASCADE')]
    private RoundJuror $juror;

    #[ORM\Column(type: 'integer')]
    private int $score;

    /** Optional juror remark, surfaced to coordinators in the results view. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(RoundImage $image, RoundJuror $juror, int $score)
    {
        $this->image = $image;
        $this->juror = $juror;
        $this->score = $score;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $image->addVote($this);
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

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): void
    {
        $this->score = $score;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
