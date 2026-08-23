<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\CampaignImage;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundSource;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\SourceType;
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
    public function importRoundSource(Round $round, ?User $importedBy = null): array
    {
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

        return $this->runImport($round, $source);
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
    public function retryRoundSource(RoundSource $source): array
    {
        $source->recordRetry();
        $this->em->flush();

        return $this->runImport($source->getRound(), $source);
    }

    /**
     * Performs the fetch, recording the outcome on the source either way.
     *
     * @return array{result: ImportResult, warnings: list<string>, images: list<CampaignImage>}
     */
    private function runImport(Round $round, RoundSource $source): array
    {
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
                    $this->em->flush();
                }
            }
        } catch (\Throwable $e) {
            // Whatever was fetched before the failure is kept: the pool
            // upserts on page id, so the retry picks up from here.
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
     * @param list<string> $fileList
     * @return iterable<CommonsFile>
     */
    private function fetchFilesFor(
        ?SourceType $type,
        ?string $category,
        array $fileList,
        ?string $url,
    ): iterable {
        // A file-list URL is fetched over HTTP either way; only the lookup
        // of the resulting titles differs.
        if ($type === SourceType::FileListUrl) {
            $fileList = $this->commons->fetchFileList((string) $url);
            $type = SourceType::FileList;
        }

        if ($this->replica?->isAvailable() === true) {
            return match ($type) {
                SourceType::Category => $this->replica->filesInCategory((string) $category),
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
