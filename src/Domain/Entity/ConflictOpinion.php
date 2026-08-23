<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A juror's stated view on a disputed image in a final jury meeting.
 *
 * When two jurors propose very different positions for the same
 * photograph, the rest of the panel needs a way to weigh in without
 * changing their own proposal. An opinion records where this juror thinks
 * the image belongs and why, so the disagreement can be settled on the
 * record rather than by whoever edits last.
 */
#[ORM\Entity]
#[ORM\Table(name: 'conflict_opinion')]
#[ORM\UniqueConstraint(name: 'uniq_opinion', columns: ['image_id', 'author_id'])]
#[ORM\Index(name: 'idx_opinion_round', columns: ['round_id'])]
class ConflictOpinion
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'author_username', type: 'string', length: 255)]
    private string $authorUsername;

    /**
     * The position this juror argues for. Null when they are commenting on
     * the dispute without proposing a number of their own.
     */
    #[ORM\Column(name: 'suggested_position', type: 'integer', nullable: true)]
    private ?int $suggestedPosition = null;

    /** Which juror's proposal this opinion sides with, if any. */
    #[ORM\Column(name: 'supports_username', type: 'string', length: 255, nullable: true)]
    private ?string $supportsUsername = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(Round $round, RoundImage $image, User $author, string $body)
    {
        $this->round = $round;
        $this->image = $image;
        $this->author = $author;
        $this->authorUsername = $author->getUsername();
        $this->body = $body;
        $this->createdAt = new DateTimeImmutable();
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorUsername(): string
    {
        return $this->authorUsername;
    }

    public function getSuggestedPosition(): ?int
    {
        return $this->suggestedPosition;
    }

    public function setSuggestedPosition(?int $position): void
    {
        $this->suggestedPosition = $position !== null && $position > 0 ? $position : null;
        $this->touch();
    }

    public function getSupportsUsername(): ?string
    {
        return $this->supportsUsername;
    }

    public function setSupportsUsername(?string $username): void
    {
        $this->supportsUsername = $username !== null && trim($username) !== ''
            ? User::canonicaliseUsername($username)
            : null;
        $this->touch();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
