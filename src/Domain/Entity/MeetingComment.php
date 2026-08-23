<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A remark made during a final jury meeting.
 *
 * Two shapes share this table: a comment attached to a specific image, and
 * one posted to the round's general discussion. The general thread can
 * still reference images — see referencedImages — so the panel can talk
 * about several photographs at once without leaving the conversation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meeting_comment')]
#[ORM\Index(name: 'idx_comment_round_created', columns: ['round_id', 'created_at'])]
#[ORM\Index(name: 'idx_comment_image', columns: ['image_id'])]
class MeetingComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Round::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    /** Null for a post in the general discussion thread. */
    #[ORM\ManyToOne(targetEntity: RoundImage::class)]
    #[ORM\JoinColumn(name: 'image_id', nullable: true, onDelete: 'CASCADE')]
    private ?RoundImage $image = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Copied so the thread still reads correctly if an account is removed. */
    #[ORM\Column(name: 'author_username', type: 'string', length: 255)]
    private string $authorUsername;

    #[ORM\Column(type: 'text')]
    private string $body;

    /**
     * Round image ids mentioned in a general-discussion post, so the UI can
     * show thumbnails alongside the text.
     *
     * @var list<int>|null
     */
    #[ORM\Column(name: 'referenced_images', type: 'json', nullable: true)]
    private ?array $referencedImages = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    public function __construct(Round $round, User $author, string $body)
    {
        $this->round = $round;
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

    public function getImage(): ?RoundImage
    {
        return $this->image;
    }

    public function setImage(?RoundImage $image): void
    {
        $this->image = $image;
    }

    public function isGeneralDiscussion(): bool
    {
        return $this->image === null;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorUsername(): string
    {
        return $this->authorUsername;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function edit(string $body): void
    {
        $this->body = $body;
        $this->editedAt = new DateTimeImmutable();
    }

    /** @return list<int> */
    public function getReferencedImages(): array
    {
        return $this->referencedImages ?? [];
    }

    /** @param list<int> $imageIds */
    public function setReferencedImages(array $imageIds): void
    {
        $this->referencedImages = $imageIds === [] ? null : array_values(array_unique($imageIds));
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function wasEdited(): bool
    {
        return $this->editedAt !== null;
    }
}
