<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\RoundState;
use JuryTool\Domain\Enum\SourceType;
use JuryTool\Domain\Enum\VotingMethod;

/**
 * A voting round within a campaign.
 *
 * Images arrive either from a Commons category or by derivation from a
 * previous round (see derivedFrom / RoundDerivationService).
 */
#[ORM\Entity]
#[ORM\Table(name: 'round')]
#[ORM\Index(name: 'idx_round_campaign_state', columns: ['campaign_id', 'state'])]
class Round
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** Ordering within the campaign; round 1, round 2, and so on. */
    #[ORM\Column(type: 'integer')]
    private int $sequence = 1;

    /** Free-text brief shown to jurors before and during voting. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(name: 'voting_deadline', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $votingDeadline = null;

    #[ORM\Column(name: 'voting_method', type: 'string', length: 32, enumType: VotingMethod::class)]
    private VotingMethod $votingMethod = VotingMethod::YesNo;

    /** Ceiling for Rating rounds; ignored by other methods. */
    #[ORM\Column(name: 'max_rating', type: 'integer')]
    private int $maxRating = 5;

    /** Set when this round's images were carried over from an earlier round. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'derived_from_round_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $derivedFrom = null;

    /**
     * Plain-language record of the criteria used to select this round's
     * images from the previous round, kept for auditability.
     */
    #[ORM\Column(name: 'derivation_criteria', type: 'string', length: 512, nullable: true)]
    private ?string $derivationCriteria = null;

    /**
     * How many jurors must vote on each image before it counts as complete.
     * 0 means every juror in the round must vote on every image.
     */
    #[ORM\Column(type: 'integer')]
    private int $quorum = 1;

    /** Whether jurors see their own progress and tallies. Off by default. */
    #[ORM\Column(name: 'show_own_statistics', type: 'boolean')]
    private bool $showOwnStatistics = false;

    #[ORM\Column(type: 'string', length: 16, enumType: RoundState::class)]
    private RoundState $state = RoundState::Draft;

    #[ORM\Embedded(class: RoundFileSettings::class, columnPrefix: false)]
    private RoundFileSettings $fileSettings;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, RoundImage> */
    #[ORM\OneToMany(targetEntity: RoundImage::class, mappedBy: 'round', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $images;

    /** @var Collection<int, RoundJuror> */
    #[ORM\OneToMany(targetEntity: RoundJuror::class, mappedBy: 'round', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $jurors;

    public function __construct(Campaign $campaign, string $name)
    {
        $this->campaign = $campaign;
        $this->name = $name;
        $this->sequence = $campaign->nextRoundSequence();
        $this->createdAt = new DateTimeImmutable();
        $this->fileSettings = new RoundFileSettings();
        $this->images = new ArrayCollection();
        $this->jurors = new ArrayCollection();
        $campaign->addRound($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): void
    {
        $this->sequence = $sequence;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): void
    {
        $this->details = $details;
    }

    public function getVotingDeadline(): ?DateTimeImmutable
    {
        return $this->votingDeadline;
    }

    public function setVotingDeadline(?DateTimeImmutable $deadline): void
    {
        $this->votingDeadline = $deadline;
    }

    public function hasDeadlinePassed(?DateTimeImmutable $now = null): bool
    {
        if ($this->votingDeadline === null) {
            return false;
        }

        return ($now ?? new DateTimeImmutable()) > $this->votingDeadline;
    }

    public function getVotingMethod(): VotingMethod
    {
        return $this->votingMethod;
    }

    public function setVotingMethod(VotingMethod $method): void
    {
        $this->votingMethod = $method;
    }

    public function getMaxRating(): int
    {
        return $this->maxRating;
    }

    public function setMaxRating(int $max): void
    {
        $this->maxRating = $max;
    }

    /**
     * Score bounds actually enforced for this round, taking maxRating into
     * account for Rating rounds.
     *
     * @return array{int, int}
     */
    public function scoreRange(): array
    {
        if ($this->votingMethod === VotingMethod::Rating) {
            return [1, $this->maxRating];
        }

        return $this->votingMethod->defaultRange();
    }

    /**
     * Where this round's images come from: an earlier round if derived,
     * otherwise the campaign's configured source.
     */
    public function sourceSummary(): ?string
    {
        if ($this->derivedFrom !== null) {
            return $this->derivedFrom->getName();
        }

        return $this->campaign->sourceSummary();
    }

    public function isDerived(): bool
    {
        return $this->derivedFrom !== null;
    }

    public function getDerivedFrom(): ?self
    {
        return $this->derivedFrom;
    }

    public function setDerivedFrom(?self $round): void
    {
        $this->derivedFrom = $round;
    }

    public function getDerivationCriteria(): ?string
    {
        return $this->derivationCriteria;
    }

    public function setDerivationCriteria(?string $criteria): void
    {
        $this->derivationCriteria = $criteria;
    }

    public function getQuorum(): int
    {
        return $this->quorum;
    }

    public function setQuorum(int $quorum): void
    {
        $this->quorum = max(0, $quorum);
    }

    /**
     * Number of votes an image needs to be considered complete. A quorum of
     * 0 is shorthand for "every juror", resolved against the current roster.
     */
    public function effectiveQuorum(): int
    {
        if ($this->quorum > 0) {
            return $this->quorum;
        }

        return max(1, $this->activeJurorCount());
    }

    public function showsOwnStatistics(): bool
    {
        return $this->showOwnStatistics;
    }

    public function setShowOwnStatistics(bool $value): void
    {
        $this->showOwnStatistics = $value;
    }

    public function getState(): RoundState
    {
        return $this->state;
    }

    public function setState(RoundState $state): void
    {
        $this->state = $state;
    }

    /**
     * Returns a finalized jury meeting to active.
     *
     * Judging rounds stay closed once closed — reopening one would let
     * votes change after results were published. A meeting is different:
     * its whole purpose is deliberation, and a panel may legitimately need
     * to revisit a decision, so this is the one way out of Finalized.
     */
    public function reopenMeeting(): void
    {
        if ($this->votingMethod !== VotingMethod::Meeting) {
            throw new \LogicException('Only a jury meeting can be reopened.');
        }

        $this->state = RoundState::Active;
    }

    /**
     * Whether a juror may cast a vote right now. Both the explicit state and
     * the deadline have to allow it.
     */
    public function acceptsVotes(?DateTimeImmutable $now = null): bool
    {
        return $this->state->acceptsVotes() && !$this->hasDeadlinePassed($now);
    }

    public function getFileSettings(): RoundFileSettings
    {
        return $this->fileSettings;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, RoundImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(RoundImage $image): void
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }
    }

    /** @return Collection<int, RoundJuror> */
    public function getJurors(): Collection
    {
        return $this->jurors;
    }

    public function addJuror(RoundJuror $juror): void
    {
        if (!$this->jurors->contains($juror)) {
            $this->jurors->add($juror);
        }
    }

    public function removeJuror(RoundJuror $juror): void
    {
        $this->jurors->removeElement($juror);
    }

    /** Images in the round that the file settings did not exclude. */
    public function qualifiedImageCount(): int
    {
        return $this->images->filter(
            static fn (RoundImage $i): bool => !$i->isDisqualified()
        )->count();
    }

    public function disqualifiedImageCount(): int
    {
        return $this->images->filter(
            static fn (RoundImage $i): bool => $i->isDisqualified()
        )->count();
    }

    public function activeJurorCount(): int
    {
        return $this->jurors->filter(
            static fn (RoundJuror $j): bool => $j->isActive()
        )->count();
    }

    /** Usernames of this round's jurors, for the disqualify-jurors import rule. */
    public function jurorUsernames(): array
    {
        return array_values(
            $this->jurors->map(
                static fn (RoundJuror $j): string => $j->getUsername()
            )->toArray()
        );
    }

    public function hasJuror(User $user): bool
    {
        foreach ($this->jurors as $juror) {
            if ($juror->getUser()?->getId() === $user->getId() && $juror->isActive()) {
                return true;
            }
        }

        return false;
    }
}
