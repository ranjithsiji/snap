<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\SourceType;

/**
 * A contest such as "Wiki Loves Earth 2026". Set up by an admin; rounds
 * within it are configured by its coordinators.
 */
#[ORM\Entity]
#[ORM\Table(name: 'campaign')]
class Campaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** URL-safe identifier, e.g. "wle-2026". */
    #[ORM\Column(type: 'string', length: 128, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Contest year, useful for grouping repeat campaigns in listings. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $year = null;

    /** When the campaign runs. Displayed on the campaign header. */
    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endsAt = null;

    /** Closed campaigns are read-only but still visible; archived ones are hidden. */
    #[ORM\Column(name: 'is_closed', type: 'boolean')]
    private bool $isClosed = false;

    /**
     * Where this campaign's images come from. Defined once here; every round
     * that imports fresh images draws from this source. Later rounds instead
     * derive their images from an earlier round.
     */
    #[ORM\Column(name: 'source_type', type: 'string', length: 32, enumType: SourceType::class)]
    private SourceType $sourceType = SourceType::Category;

    /** Commons category, without the "Category:" prefix. */
    #[ORM\Column(name: 'source_category', type: 'string', length: 512, nullable: true)]
    private ?string $sourceCategory = null;

    /** URL of a newline-separated file list, when sourceType is FileListUrl. */
    #[ORM\Column(name: 'source_url', type: 'string', length: 1024, nullable: true)]
    private ?string $sourceUrl = null;

    /** Inline newline-separated file list, when sourceType is FileList. */
    #[ORM\Column(name: 'source_file_list', type: 'text', nullable: true)]
    private ?string $sourceFileList = null;

    #[ORM\Column(name: 'is_archived', type: 'boolean')]
    private bool $isArchived = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, Round> */
    #[ORM\OneToMany(targetEntity: Round::class, mappedBy: 'campaign', cascade: ['persist'])]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $rounds;

    /**
     * Master pool of files imported from the campaign source when the
     * campaign was created. Rounds select from this rather than re-querying
     * Commons.
     *
     * @var Collection<int, CampaignImage>
     */
    #[ORM\OneToMany(
        targetEntity: CampaignImage::class,
        mappedBy: 'campaign',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $images;

    /** Set once the campaign's source has been imported into the pool. */
    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $importedAt = null;

    /**
     * Commons users holding a role in this campaign. Drives the
     * "disqualify coordinators / maintainers / organizers" import rules.
     *
     * @var Collection<int, CampaignParticipant>
     */
    #[ORM\OneToMany(
        targetEntity: CampaignParticipant::class,
        mappedBy: 'campaign',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $participants;

    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new DateTimeImmutable();
        $this->rounds = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->participants = new ArrayCollection();
    }

    /** @return Collection<int, CampaignImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(CampaignImage $image): void
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }
    }

    public function getImportedAt(): ?DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function markImported(): void
    {
        $this->importedAt = new DateTimeImmutable();
    }

    public function hasBeenImported(): bool
    {
        return $this->importedAt !== null;
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

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): void
    {
        $this->year = $year;
    }

    public function getSourceType(): SourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(SourceType $type): void
    {
        $this->sourceType = $type;
    }

    public function getSourceCategory(): ?string
    {
        return $this->sourceCategory;
    }

    public function setSourceCategory(?string $category): void
    {
        $this->sourceCategory = $category !== null && trim($category) !== ''
            ? preg_replace('/^Category:/i', '', trim(str_replace('_', ' ', $category)))
            : null;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $url): void
    {
        $this->sourceUrl = $url !== null && trim($url) !== '' ? trim($url) : null;
    }

    public function getSourceFileList(): ?string
    {
        return $this->sourceFileList;
    }

    public function setSourceFileList(?string $list): void
    {
        $this->sourceFileList = $list !== null && trim($list) !== '' ? $list : null;
    }

    /**
     * File titles parsed out of the inline file list, one per line, with
     * blank lines and any "File:" prefix normalised away.
     *
     * @return list<string>
     */
    public function parsedFileList(): array
    {
        return self::parseTitles($this->sourceFileList ?? '');
    }

    /**
     * Splits newline-separated file titles. Shared with the file-list-URL
     * path, which fetches the same format over HTTP.
     *
     * @return list<string>
     */
    public static function parseTitles(string $raw): array
    {
        $titles = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $titles[] = preg_replace('/^File:/i', '', $line) ?? $line;
        }

        return $titles;
    }

    /** Human-readable description of where this campaign's images come from. */
    public function sourceSummary(): ?string
    {
        return match ($this->sourceType) {
            SourceType::Category => $this->sourceCategory,
            SourceType::FileListUrl => $this->sourceUrl,
            SourceType::FileList => sprintf('%d file(s)', count($this->parsedFileList())),
            SourceType::PreviousRound => null,
        };
    }

    /** Whether the campaign has enough configuration for a round to import. */
    public function hasUsableSource(): bool
    {
        return match ($this->sourceType) {
            SourceType::Category => $this->sourceCategory !== null,
            SourceType::FileListUrl => $this->sourceUrl !== null,
            SourceType::FileList => $this->parsedFileList() !== [],
            SourceType::PreviousRound => false,
        };
    }

    public function getStartsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?DateTimeImmutable $at): void
    {
        $this->startsAt = $at;
    }

    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?DateTimeImmutable $at): void
    {
        $this->endsAt = $at;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function setClosed(bool $closed): void
    {
        $this->isClosed = $closed;
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

    /** @return Collection<int, Round> */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }

    public function addRound(Round $round): void
    {
        if (!$this->rounds->contains($round)) {
            $this->rounds->add($round);
        }
    }

    /** @return Collection<int, CampaignParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(CampaignParticipant $participant): void
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
        }
    }

    public function removeParticipant(CampaignParticipant $participant): void
    {
        $this->participants->removeElement($participant);
    }

    /** Next unused sequence number, so rounds order predictably. */
    public function nextRoundSequence(): int
    {
        $max = 0;
        foreach ($this->rounds as $round) {
            $max = max($max, $round->getSequence());
        }

        return $max + 1;
    }
}
