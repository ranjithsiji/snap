<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\SourceType;

/**
 * A record of one import into a round.
 *
 * A round can be filled more than once — a category imported, then a
 * handful of files added by name, then a top-up after Commons catches
 * more entries. Keeping a row per import means a coordinator can see where
 * any given image came from, and re-run or audit a single source without
 * disturbing the rest.
 */
#[ORM\Entity]
#[ORM\Table(name: 'round_source')]
#[ORM\Index(name: 'idx_source_round', columns: ['round_id'])]
class RoundSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Round::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, onDelete: 'CASCADE')]
    private Round $round;

    #[ORM\Column(type: 'string', length: 32, enumType: SourceType::class)]
    private SourceType $type;

    /** The category name, list URL, or a summary of an inline list. */
    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'files_seen', type: 'integer')]
    private int $filesSeen = 0;

    #[ORM\Column(name: 'files_added', type: 'integer')]
    private int $filesAdded = 0;

    /**
     * Files the import could not resolve — misspelled names, deleted or
     * renamed pages. Surfaced to the coordinator rather than dropped, since
     * a silently missing entry is how a photograph goes unjudged.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $warnings = null;

    /**
     * Whether the import ran to completion.
     *
     * Large categories take minutes and can fail partway — a timeout, a
     * Commons hiccup. A failed source stays on the round with its error so
     * a coordinator can see what happened and retry; because imports upsert
     * on Commons page id, a retry resumes rather than duplicating.
     */
    #[ORM\Column(name: 'is_complete', type: 'boolean')]
    private bool $isComplete = false;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'attempt_count', type: 'integer')]
    private int $attemptCount = 1;

    /**
     * How far through the source the last attempt got.
     *
     * For a category this is the highest `cl_from` written, which is what
     * the replica query pages on — so a resumed import asks for rows after
     * it rather than re-reading from the beginning. Without this a retry
     * costs the same as the original however little was left: correct,
     * because the upsert absorbs the repeats, but a category of tens of
     * thousands is minutes of work to establish nothing changed.
     *
     * Null until something has been written, and cleared once the source
     * completes so a later re-import picks up new files from the start.
     */
    #[ORM\Column(name: 'resume_cursor', type: 'bigint', nullable: true)]
    private ?int $resumeCursor = null;

    #[ORM\Column(name: 'imported_by', type: 'string', length: 255, nullable: true)]
    private ?string $importedBy = null;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private DateTimeImmutable $importedAt;

    public function __construct(Round $round, SourceType $type, ?string $reference = null)
    {
        $this->round = $round;
        $this->type = $type;
        $this->reference = $reference;
        $this->importedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getType(): SourceType
    {
        return $this->type;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getFilesSeen(): int
    {
        return $this->filesSeen;
    }

    public function getFilesAdded(): int
    {
        return $this->filesAdded;
    }

    public function recordCounts(int $seen, int $added): void
    {
        $this->filesSeen = $seen;
        $this->filesAdded = $added;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings ?? [];
    }

    /** @param list<string> $warnings */
    public function setWarnings(array $warnings): void
    {
        $this->warnings = $warnings === [] ? null : array_values($warnings);
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== null && $this->warnings !== [];
    }

    public function setImportedBy(?User $user): void
    {
        $this->importedBy = $user?->getUsername();
    }

    public function getImportedBy(): ?string
    {
        return $this->importedBy;
    }

    public function getImportedAt(): DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function markComplete(): void
    {
        $this->isComplete = true;
        $this->errorMessage = null;
        // The whole source has been read, so there is nothing to resume
        // from. Clearing it means a later re-import of the same category
        // starts over and picks up files added since.
        $this->resumeCursor = null;
    }

    /**
     * Where a resumed import should continue from, if anywhere.
     *
     * Only meaningful for a source that failed partway: a completed one
     * has no cursor, and a fresh one has not written anything yet.
     */
    public function getResumeCursor(): ?int
    {
        return $this->isComplete ? null : $this->resumeCursor;
    }

    public function recordResumeCursor(?int $cursor): void
    {
        if ($cursor !== null && $cursor > ($this->resumeCursor ?? 0)) {
            $this->resumeCursor = $cursor;
        }
    }

    /**
     * Forgets the resume point so the next attempt reads from the start.
     *
     * Resuming skips everything before the cursor, which is the point of
     * it — but that also means a file added to the category earlier in the
     * ordering would never be seen. This is how a coordinator asks for the
     * whole source to be read again.
     */
    public function clearResumeCursor(): void
    {
        $this->resumeCursor = null;
    }

    public function markFailed(string $message): void
    {
        $this->isComplete = false;
        // Truncated: a stack-trace-laden driver message helps nobody in a
        // dialog, and the full text is in the log.
        $this->errorMessage = mb_substr($message, 0, 1000);
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function hasFailed(): bool
    {
        return !$this->isComplete && $this->errorMessage !== null;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    /** Records another attempt at the same source. */
    public function recordRetry(): void
    {
        $this->attemptCount++;
        $this->importedAt = new DateTimeImmutable();
    }

    /** Short description for the round's import history. */
    public function summary(): string
    {
        return match ($this->type) {
            SourceType::Category => 'Category: ' . (string) $this->reference,
            SourceType::FileListUrl => 'File list: ' . (string) $this->reference,
            SourceType::FileList => sprintf('%d file(s) by name', $this->filesSeen),
            SourceType::PreviousRound => 'Derived from ' . (string) $this->reference,
        };
    }
}
