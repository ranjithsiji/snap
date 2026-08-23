<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\CampaignImage;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundSource;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\SourceType;
use JuryTool\Infrastructure\Commons\CategoryTooLargeException;
use JuryTool\Infrastructure\Commons\CommonsClient;
use JuryTool\Infrastructure\Commons\CommonsFile;
use JuryTool\Infrastructure\Commons\ReplicaClient;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fills a campaign's master image pool from its configured source.
 *
 * Runs once when the campaign is created; can be re-run to pick up files
 * added to the source category since, which is why it upserts on Commons
 * page id rather than assuming an empty pool.
 */
class ImportService
{
    /** Flush every N entities so very large categories stay within memory. */
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly CommonsClient $commons,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        /**
         * Set only on Toolforge. Reading a category from the replica is a
         * single query rather than dozens of paginated API calls, so it is
         * used whenever it is reachable.
         */
        private readonly ?ReplicaClient $replica = null,
    ) {
    }

    /** Which source of Commons metadata this deployment will use. */
    public function metadataSource(): string
    {
        return $this->replica?->isAvailable() === true ? 'replica' : 'api';
    }

    /**
     * How many files a category holds, so the UI can show what an import
     * is about to fetch. Only cheap enough to offer on the replica.
     */
    public function countCategory(string $category): ?int
    {
        if ($this->replica?->isAvailable() !== true) {
            return null;
        }

        return $this->replica->countCategory($category);
    }

    /**
     * Imports the campaign's source into its pool.
     *
     * @return ImportResult Counts of what was added, updated and skipped.
     */
    public function importCampaign(Campaign $campaign): ImportResult
    {
        if (!$campaign->hasUsableSource()) {
            throw new RuntimeException('Campaign has no usable source configured.');
        }

        $this->logger->info('Starting campaign import', [
            'campaign' => $campaign->getId(),
            'source' => $campaign->getSourceType()->value,
        ]);

        $existing = $this->existingPageIds($campaign);

        $added = 0;
        $updated = 0;
        $processed = 0;

        foreach ($this->fetchFiles($campaign) as $file) {
            $processed++;

            if (isset($existing[$file->pageId])) {
                if ($this->refresh($campaign, $file)) {
                    $updated++;
                }
            } else {
                $this->create($campaign, $file);
                $existing[$file->pageId] = true;
                $added++;
            }

            if (($processed % self::BATCH_SIZE) === 0) {
                $this->em->flush();
                $this->detachImported($campaign);
            }
        }

        $campaign->markImported();
        $this->em->flush();

        $result = new ImportResult($processed, $added, $updated);

        $this->logger->info('Campaign import finished', [
            'campaign' => $campaign->getId(),
            'seen' => $processed,
            'added' => $added,
            'updated' => $updated,
        ]);

        return $result;
    }

    /**
     * Imports a round's own Commons source into the campaign pool.
     *
     * Parallel rounds of one campaign — Trees, Rivers — each gather their
     * own category. The files still land in the campaign's pool so an image
     * appearing in two categories is stored once, but only this round's
     * files are returned for it to draw on.
     *
     * @return array{result: ImportResult, images: list<CampaignImage>}
     */
    public function importRoundSource(
        Round $round,
        ?User $importedBy = null,
        // Called every batch, so a long import can report progress instead
        // of looking hung. Ignored by the web path, which has nowhere to
        // show it.
        ?callable $onProgress = null,
    ): array {
        if (!$round->hasOwnSource()) {
            throw new RuntimeException('This round has no source of its own configured.');
        }

        // Every import is recorded, so a round filled from several sources
        // stays auditable and unresolved filenames are not lost. Persisted
        // before the fetch begins, so a failure partway leaves a record a
        // coordinator can see and retry rather than vanishing.
        $source = new RoundSource(
            $round,
            $round->getSourceType(),
            $round->getSourceCategory() ?? $round->getSourceUrl(),
        );
        $source->setImportedBy($importedBy);

        $this->em->persist($source);
        $this->em->flush();

        return $this->runImport($round, $source, $onProgress);
    }

    /**
     * Retries a failed or partial import.
     *
     * Files already stored are matched on Commons page id and updated
     * rather than duplicated, so a retry resumes where the last attempt
     * stopped instead of starting over.
     *
     * @return array{result: ImportResult, warnings: list<string>, images: list<CampaignImage>}
     */
    public function retryRoundSource(RoundSource $source, ?callable $onProgress = null): array
    {
        $source->recordRetry();
        $this->em->flush();

        return $this->runImport($source->getRound(), $source, $onProgress);
    }

    /**
     * The round's last import, if it did not finish.
     *
     * A re-run continues that attempt rather than starting a new one:
     * only the unfinished attempt carries a resume cursor, and beginning
     * afresh each time would re-read the whole category however little
     * was left to do.
     */
    public function resumableSource(Round $round): ?RoundSource
    {
        $source = $this->em->getRepository(RoundSource::class)->findOneBy(
            ['round' => $round],
            ['id' => 'DESC'],
        );

        return $source !== null && !$source->isComplete() ? $source : null;
    }

    /**
     * Performs the fetch, recording the outcome on the source either way.
     *
     * @return array{result: ImportResult, warnings: list<string>, images: list<CampaignImage>}
     */
    private function runImport(
        Round $round,
        RoundSource $source,
        ?callable $onProgress = null,
    ): array {
        $campaign = $round->getCampaign();
        $existing = $this->existingPageIds($campaign);

        $added = 0;
        $updated = 0;
        $processed = 0;
        $pageIds = [];

        try {
            foreach ($this->fetchFilesFor(
                $round->getSourceType(),
                $round->getSourceCategory(),
                $round->parsedFileList(),
                $round->getSourceUrl(),
                // Where the last attempt stopped, so a resumed import does
                // not re-read what it already wrote.
                $source->getResumeCursor() ?? 0,
            ) as $file) {
                $processed++;
                $pageIds[] = $file->pageId;

                if (isset($existing[$file->pageId])) {
                    if ($this->refresh($campaign, $file)) {
                        $updated++;
                    }
                } else {
                    $this->create($campaign, $file);
                    $existing[$file->pageId] = true;
                    $added++;
                }

                if (($processed % self::BATCH_SIZE) === 0) {
                    // Advanced only alongside the flush that durably writes
                    // the rows it covers; recording it earlier would let a
                    // crash skip files that were never actually stored.
                    // Null for API-sourced files, which carry no cursor.
                    $source->recordResumeCursor($file->resumeCursor);

                    $this->em->flush();
                    $this->detachImported($campaign);

                    if ($onProgress !== null) {
                        $onProgress($processed, $added, $updated);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Whatever was fetched before the failure is kept, along with
            // the cursor recorded at the last flush — so a retry resumes
            // from there rather than re-reading the category. The upsert
            // still absorbs any overlap.
            $this->em->flush();

            $source->recordCounts($processed, $added);
            $source->markFailed($e->getMessage());
            $this->em->flush();

            $this->logger->error('Round import failed', [
                'round' => $round->getId(),
                'source' => $source->getId(),
                'imported_so_far' => $processed,
                'error' => $e->getMessage(),
            ]);

            // An oversized category is refused before anything is read, so
            // there is nothing to resume and retrying would fail the same
            // way. Its own message already says what to do instead.
            if ($e instanceof CategoryTooLargeException) {
                throw $e;
            }

            throw new RuntimeException(sprintf(
                'Import failed after %d file(s): %s. The %d file(s) already fetched were kept — '
                . 'retry to continue from there.',
                $processed,
                $e->getMessage(),
                $added,
            ), 0, $e);
        }

        $round->markImported();

        if (!$campaign->hasBeenImported()) {
            $campaign->markImported();
        }

        $source->recordCounts($processed, $added);
        $source->setWarnings($this->commons->lastWarnings());
        $source->markComplete();

        $this->em->flush();

        $this->logger->info('Round source imported', [
            'round' => $round->getId(),
            'seen' => $processed,
            'added' => $added,
            'updated' => $updated,
        ]);

        return [
            'result' => new ImportResult($processed, $added, $updated),
            'warnings' => $source->getWarnings(),
            'images' => $pageIds === []
                ? []
                : $this->em->getRepository(CampaignImage::class)->findBy([
                    'campaign' => $campaign,
                    'commonsPageId' => $pageIds,
                ]),
        ];
    }

    /**
     * Resolves the campaign source into a stream of Commons files.
     *
     * @return iterable<CommonsFile>
     */
    private function fetchFiles(Campaign $campaign): iterable
    {
        return match ($campaign->getSourceType()) {
            SourceType::Category => $this->commons->filesInCategory(
                (string) $campaign->getSourceCategory()
            ),
            SourceType::FileList => $this->commons->filesByTitle(
                $campaign->parsedFileList()
            ),
            SourceType::FileListUrl => $this->commons->filesByTitle(
                $this->commons->fetchFileList((string) $campaign->getSourceUrl())
            ),
            SourceType::PreviousRound => throw new RuntimeException(
                'A campaign cannot source its pool from a round.'
            ),
        };
    }

    /**
     * Resolves any source description into a stream of Commons files.
     *
     * Where the stream is keyed, the key is a resume cursor the caller can
     * record; a list-based source is keyed by position and offers none.
     *
     * @param list<string> $fileList
     * @param int $after Resume point, honoured by the replica category path
     * @return iterable<CommonsFile>
     */
    private function fetchFilesFor(
        ?SourceType $type,
        ?string $category,
        array $fileList,
        ?string $url,
        int $after = 0,
    ): iterable {
        // A file-list URL is fetched over HTTP either way; only the lookup
        // of the resulting titles differs.
        if ($type === SourceType::FileListUrl) {
            $fileList = $this->commons->fetchFileList((string) $url);
            $type = SourceType::FileList;
        }

        if ($this->replica?->isAvailable() === true) {
            return match ($type) {
                // Streamed rather than collected: a large category would
                // otherwise sit in memory in full while its entities are
                // being written. Keyed by cl_from, so an interrupted import
                // can resume from where it stopped.
                SourceType::Category => $this->replica->streamFilesInCategory(
                    (string) $category,
                    $after,
                ),
                SourceType::FileList => $this->replica->filesByTitle($fileList),
                default => throw new RuntimeException('No usable source configured.'),
            };
        }

        return match ($type) {
            SourceType::Category => $this->commons->filesInCategory((string) $category),
            SourceType::FileList => $this->commons->filesByTitle($fileList),
            default => throw new RuntimeException('No usable source configured.'),
        };
    }

    /**
     * Page ids already in the pool, as a lookup set. Queried as scalars so
     * a large existing pool does not get hydrated into memory.
     *
     * @return array<int, true>
     */
    private function existingPageIds(Campaign $campaign): array
    {
        if ($campaign->getId() === null) {
            return [];
        }

        $rows = $this->em->createQuery(
            'SELECT ci.commonsPageId FROM ' . CampaignImage::class . ' ci WHERE ci.campaign = :campaign'
        )->setParameter('campaign', $campaign)->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['commonsPageId']] = true;
        }

        return $ids;
    }

    /**
     * Frees the images written so far from the identity map.
     *
     * flush() alone does not free them: Doctrine keeps every managed
     * entity for the life of the request, so a category of a hundred
     * thousand grows without bound however often it is flushed.
     *
     * Detached individually rather than with clear(). ORM 3 removed
     * clear()'s per-class argument, and clearing everything would detach
     * the campaign, round and source that stay live across the loop —
     * after which the next persist() fails with "a new entity was found
     * through a relationship that was not configured to cascade".
     */
    private function detachImported(Campaign $campaign): void
    {
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[CampaignImage::class] ?? [] as $image) {
            if ($image instanceof CampaignImage) {
                $this->em->detach($image);
            }
        }

        // The campaign's collection would otherwise still reference every
        // detached image, holding them all alive anyway.
        if ($campaign->getImages() instanceof PersistentCollection) {
            $campaign->getImages()->setInitialized(false);
        }
    }

    private function create(Campaign $campaign, CommonsFile $file): void
    {
        $image = new CampaignImage(
            $campaign,
            $file->pageId,
            $file->title,
            $file->fileUrl,
        );

        $this->applyMetadata($image, $file);

        $this->em->persist($image);
    }

    /**
     * Updates a pooled image from freshly fetched metadata.
     *
     * @return bool True when something actually changed, so the caller can
     *              report a meaningful "updated" count.
     */
    private function refresh(Campaign $campaign, CommonsFile $file): bool
    {
        $image = $this->em->getRepository(CampaignImage::class)->findOneBy([
            'campaign' => $campaign,
            'commonsPageId' => $file->pageId,
        ]);

        if ($image === null) {
            return false;
        }

        // Files get renamed on Commons; the page id is what stays stable.
        $changed = $image->getTitle() !== $file->title
            || $image->getFileUrl() !== $file->fileUrl;

        $image->setTitle($file->title);
        $image->setFileUrl($file->fileUrl);
        $this->applyMetadata($image, $file);

        return $changed;
    }

    private function applyMetadata(CampaignImage $image, CommonsFile $file): void
    {
        $image->setDescriptionUrl($file->descriptionUrl);
        $image->setThumbUrl($file->thumbUrl);
        $image->setDimensions($file->width, $file->height);
        $image->setMimeType($file->mimeType);
        $image->setUploader($file->uploader);
        $image->setUploadedAt($file->uploadedAt);
    }
}
