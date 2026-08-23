<?php

declare(strict_types=1);

namespace JuryTool\Service;

/**
 * Outcome of a campaign import, reported back to the coordinator.
 */
final readonly class ImportResult
{
    public function __construct(
        /** Files seen in the source. */
        public int $processed,
        /** Files newly added to the pool. */
        public int $added,
        /** Existing pool entries whose Commons metadata had changed. */
        public int $updated,
    ) {
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'added' => $this->added,
            'updated' => $this->updated,
        ];
    }
}
