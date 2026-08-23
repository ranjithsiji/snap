<?php

declare(strict_types=1);

namespace JuryTool\Service;

/**
 * Outcome of populating a round or re-applying its file settings.
 */
final readonly class PopulationResult
{
    public function __construct(
        /** Images added, or entries changed when re-applying rules. */
        public int $added,
        /** Images the round's rules excluded. */
        public int $disqualified,
        /** Images left untouched — already present, or protected by votes. */
        public int $skipped,
    ) {
    }

    public function qualified(): int
    {
        return max(0, $this->added - $this->disqualified);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'disqualified' => $this->disqualified,
            'skipped' => $this->skipped,
        ];
    }
}
