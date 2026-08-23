<?php

declare(strict_types=1);

namespace JuryTool\Support;

use JuryTool\Domain\Enum\VotingMethod;

/**
 * Rules for choosing which images advance from one round to the next.
 *
 * Criteria compose: setting both a minimum score and a top-N limit takes
 * the top N of those that also clear the score floor.
 */
final readonly class DerivationCriteria
{
    public function __construct(
        /** Keep only images whose aggregate score is at least this. */
        public ?float $minAverageScore = null,
        /** Keep only images with at least this many votes. */
        public ?int $minVoteCount = null,
        /** Keep only the best N images after the other filters apply. */
        public ?int $topN = null,
        /**
         * For yes/no rounds: require this many accept votes. Expressed
         * separately from minAverageScore because "at least 2 jurors said
         * yes" is the usual phrasing, not "average above 0.66".
         */
        public ?int $minAcceptCount = null,
        /** Carry disqualified images over as well. Almost never wanted. */
        public bool $includeDisqualified = false,
    ) {
    }

    /**
     * Builds criteria from an API payload, ignoring absent keys.
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $number = static function (mixed $value): ?float {
            if ($value === null || $value === '') {
                return null;
            }

            return is_numeric($value) ? (float) $value : null;
        };

        $integer = static function (mixed $value): ?int {
            if ($value === null || $value === '') {
                return null;
            }

            return is_numeric($value) ? (int) $value : null;
        };

        return new self(
            minAverageScore: $number($input['minAverageScore'] ?? null),
            minVoteCount: $integer($input['minVoteCount'] ?? null),
            topN: $integer($input['topN'] ?? null),
            minAcceptCount: $integer($input['minAcceptCount'] ?? null),
            includeDisqualified: (bool) ($input['includeDisqualified'] ?? false),
        );
    }

    /** Whether any filter is set; an empty criteria set advances everything. */
    public function isEmpty(): bool
    {
        return $this->minAverageScore === null
            && $this->minVoteCount === null
            && $this->topN === null
            && $this->minAcceptCount === null;
    }

    /**
     * Plain-language description of the criteria, stored on the derived
     * round so coordinators can see later how its shortlist was chosen.
     */
    public function describe(VotingMethod $method): string
    {
        $parts = [];

        if ($this->minAcceptCount !== null) {
            $parts[] = sprintf('at least %d accept vote(s)', $this->minAcceptCount);
        }

        if ($this->minAverageScore !== null) {
            $parts[] = sprintf(
                '%s score %s %s',
                $method === VotingMethod::RankOrder ? 'average rank' : 'average',
                $method === VotingMethod::RankOrder ? 'at most' : 'at least',
                rtrim(rtrim(number_format($this->minAverageScore, 2), '0'), '.'),
            );
        }

        if ($this->minVoteCount !== null) {
            $parts[] = sprintf('at least %d vote(s)', $this->minVoteCount);
        }

        if ($this->topN !== null) {
            $parts[] = sprintf('top %d', $this->topN);
        }

        if ($parts === []) {
            return 'all images';
        }

        return implode(', ', $parts);
    }
}
