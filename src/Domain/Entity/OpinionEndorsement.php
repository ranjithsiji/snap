<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One juror agreeing or disagreeing with another's opinion.
 *
 * Gives the panel a quick way to show which argument has support without
 * everyone writing a paragraph. One endorsement per juror per opinion, so
 * the tally reflects people rather than volume of typing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'opinion_endorsement')]
#[ORM\UniqueConstraint(name: 'uniq_endorsement', columns: ['opinion_id', 'juror_id'])]
class OpinionEndorsement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ConflictOpinion::class)]
    #[ORM\JoinColumn(name: 'opinion_id', nullable: false, onDelete: 'CASCADE')]
    private ConflictOpinion $opinion;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'juror_id', nullable: false, onDelete: 'CASCADE')]
    private User $juror;

    #[ORM\Column(name: 'juror_username', type: 'string', length: 255)]
    private string $jurorUsername;

    /** +1 agrees, -1 disagrees. */
    #[ORM\Column(type: 'smallint')]
    private int $value;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(ConflictOpinion $opinion, User $juror, int $value)
    {
        $this->opinion = $opinion;
        $this->juror = $juror;
        $this->jurorUsername = $juror->getUsername();
        $this->createdAt = new DateTimeImmutable();
        $this->setValue($value);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOpinion(): ConflictOpinion
    {
        return $this->opinion;
    }

    public function getJuror(): User
    {
        return $this->juror;
    }

    public function getJurorUsername(): string
    {
        return $this->jurorUsername;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): void
    {
        // Anything other than agree or disagree is meaningless here.
        $this->value = $value >= 0 ? 1 : -1;
    }

    public function agrees(): bool
    {
        return $this->value > 0;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
