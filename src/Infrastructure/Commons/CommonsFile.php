<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Commons;

use DateTimeImmutable;

/**
 * One file's metadata as returned by the Commons API, normalised into a
 * shape the importer can consume without knowing about API response layout.
 */
final readonly class CommonsFile
{
    public function __construct(
        public int $pageId,
        public string $title,
        public string $fileUrl,
        public ?string $descriptionUrl,
        public ?string $thumbUrl,
        public int $width,
        public int $height,
        public ?string $mimeType,
        public ?string $uploader,
        public ?DateTimeImmutable $uploadedAt,
    ) {
    }

    /**
     * Builds an instance from a page object in an API response, or returns
     * null when the page carries no usable imageinfo (missing files, pages
     * that are not files, and redirects all show up this way).
     *
     * @param array<string, mixed> $page
     */
    public static function fromApiPage(array $page): ?self
    {
        if (!isset($page['pageid'], $page['title'])) {
            return null;
        }

        $info = $page['imageinfo'][0] ?? null;
        if (!is_array($info) || !isset($info['url'])) {
            return null;
        }

        $timestamp = null;
        if (isset($info['timestamp']) && is_string($info['timestamp'])) {
            $parsed = DateTimeImmutable::createFromFormat(
                DateTimeImmutable::ATOM,
                $info['timestamp']
            );
            $timestamp = $parsed !== false ? $parsed : null;
        }

        return new self(
            pageId: (int) $page['pageid'],
            title: (string) $page['title'],
            fileUrl: (string) $info['url'],
            descriptionUrl: isset($info['descriptionurl']) ? (string) $info['descriptionurl'] : null,
            thumbUrl: isset($info['thumburl']) ? (string) $info['thumburl'] : null,
            width: (int) ($info['width'] ?? 0),
            height: (int) ($info['height'] ?? 0),
            mimeType: isset($info['mime']) ? (string) $info['mime'] : null,
            uploader: isset($info['user']) ? (string) $info['user'] : null,
            uploadedAt: $timestamp,
        );
    }

    public function pixelCount(): int
    {
        return $this->width * $this->height;
    }

    /** Whether this is a bitmap image; Commons categories also contain video and audio. */
    public function isImage(): bool
    {
        return $this->mimeType === null || str_starts_with($this->mimeType, 'image/');
    }
}
