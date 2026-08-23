<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Commons file in the campaign's master pool.
 *
 * Imported once when the campaign is created, from whichever source the
 * campaign defines. Rounds do not re-fetch from Commons: round one draws
 * from this pool, and later rounds derive from earlier rounds. Keeping the
 * Commons metadata here means the per-round disqualification rules can be
 * evaluated locally without hitting the API again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'campaign_image')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_page', columns: ['campaign_id', 'commons_page_id'])]
#[ORM\Index(name: 'idx_campaign_image_uploader', columns: ['campaign_id', 'uploader'])]
class CampaignImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    /** Commons page id — stable across file renames. */
    #[ORM\Column(name: 'commons_page_id', type: 'integer')]
    private int $commonsPageId;

    /** Full page title including the "File:" prefix. */
    #[ORM\Column(type: 'string', length: 512)]
    private string $title;

    #[ORM\Column(name: 'file_url', type: 'string', length: 1024)]
    private string $fileUrl;

    #[ORM\Column(name: 'description_url', type: 'string', length: 1024, nullable: true)]
    private ?string $descriptionUrl = null;

    #[ORM\Column(name: 'thumb_url', type: 'string', length: 1024, nullable: true)]
    private ?string $thumbUrl = null;

    #[ORM\Column(type: 'integer')]
    private int $width = 0;

    #[ORM\Column(type: 'integer')]
    private int $height = 0;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 128, nullable: true)]
    private ?string $mimeType = null;

    /** Commons uploader, canonicalised — drives the disqualify-by-role rules. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $uploader = null;

    #[ORM\Column(name: 'uploaded_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $uploadedAt = null;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private DateTimeImmutable $importedAt;

    public function __construct(Campaign $campaign, int $commonsPageId, string $title, string $fileUrl)
    {
        $this->campaign = $campaign;
        $this->commonsPageId = $commonsPageId;
        $this->title = $title;
        $this->fileUrl = $fileUrl;
        $this->importedAt = new DateTimeImmutable();
        $campaign->addImage($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getCommonsPageId(): int
    {
        return $this->commonsPageId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /** Title without the "File:" prefix, for display. */
    public function getDisplayName(): string
    {
        return preg_replace('/^File:/i', '', $this->title) ?? $this->title;
    }

    public function getFileUrl(): string
    {
        return $this->fileUrl;
    }

    public function setFileUrl(string $url): void
    {
        $this->fileUrl = $url;
    }

    public function getDescriptionUrl(): ?string
    {
        return $this->descriptionUrl;
    }

    public function setDescriptionUrl(?string $url): void
    {
        $this->descriptionUrl = $url;
    }

    public function getThumbUrl(): ?string
    {
        return $this->thumbUrl;
    }

    public function setThumbUrl(?string $url): void
    {
        $this->thumbUrl = $url;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setDimensions(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getPixelCount(): int
    {
        return $this->width * $this->height;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }

    public function getUploader(): ?string
    {
        return $this->uploader;
    }

    public function setUploader(?string $uploader): void
    {
        $this->uploader = $uploader !== null && trim($uploader) !== ''
            ? User::canonicaliseUsername($uploader)
            : null;
    }

    public function getUploadedAt(): ?DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(?DateTimeImmutable $at): void
    {
        $this->uploadedAt = $at;
    }

    public function getImportedAt(): DateTimeImmutable
    {
        return $this->importedAt;
    }
}
