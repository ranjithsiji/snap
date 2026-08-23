<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\CampaignImage;
use JuryTool\Domain\Enum\SourceType;
use JuryTool\Infrastructure\Commons\CommonsClient;
use JuryTool\Infrastructure\Commons\CommonsFile;
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
    ) {
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
