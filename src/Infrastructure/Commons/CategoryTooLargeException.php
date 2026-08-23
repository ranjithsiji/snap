<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Commons;

use RuntimeException;

/**
 * Raised when a category holds more files than one import can assemble.
 *
 * Every row is held in memory until the import completes, so a category
 * far larger than any real campaign has to be refused rather than started
 * and abandoned partway. Refusing is deliberate: a truncated import looks
 * exactly like a successful one until a photograph turns out to have gone
 * unjudged.
 */
class CategoryTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly int $found,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'The category "%s" holds %s files, more than the %s this tool '
            . 'imports at once. Import a narrower category, or raise '
            . 'REPLICA_MAX_FILES if the machine has the memory for it.',
            str_replace('_', ' ', $category),
            number_format($found),
            number_format($limit),
        ));
    }
}
