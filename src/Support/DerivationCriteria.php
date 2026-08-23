<?php

declare(strict_types=1);

namespace JuryTool\Support;

use JuryTool\Domain\Enum\VotingMethod;

/**
 * Rules for choosing which images advance from one round to the next.
 *
 * A campaign is usually judged in escalating levels — yes/no to thin the
 * field, star rating to sort it, ranking to order the shortlist, then a
 * jury meeting to settle the result — and each level inherits from the one
 * before it by a threshold such as "half the jury rated it above three
 * stars". The levels are interchangeable: any method may follow any other,
 * except that nothing follows a jury meeting.
 *
 * Criteria compose. Setting a score threshold and a top-N limit takes the
 * best N of those that also clear the threshold.
 */
final readonly class DerivationCriteria
{
    public function __construct(
        /** Keep images whose aggregate score is at least this. */
        public ?float $minAverageScore = null,
        /** Keep images with at least this many votes. */
        public ?int $minVoteCount = null,
        /** Keep only the best N after the other filters apply. */
        public ?int $topN = null,
        /**
         * For yes/no rounds: require this many accept votes.
         */
        public ?int $minAcceptCount = null,
        /**
         * Keep images that at least this fraction of jurors scored at or
         * above `scoreThreshold` — "half the jury gave it 4 or more".
         *
         * Expressed as a fraction between 0 and 1. Proportions travel
         * better than counts between rounds, since the size of the panel
         * changes from one level to the next.
         */
        public ?float $minVoterFraction = null,
        /** The score a vote must reach to count towards minVoterFraction. */
        public ?int $scoreThreshold = null,
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

        $fraction = $number($input['minVoterFraction'] ?? null);

        // Accept "50" as readily as "0.5"; a fraction above 1 can only have
        // been meant as a percentage.
        if ($fraction !== null && $fraction > 1) {
            $fraction /= 100;
        }

        return new self(
            minAverageScore: $number($input['minAverageScore'] ?? null),
            minVoteCount: $integer($input['minVoteCount'] ?? null),
            topN: $integer($input['topN'] ?? null),
            minAcceptCount: $integer($input['minAcceptCount'] ?? null),
            minVoterFraction: $fraction !== null ? max(0.0, min(1.0, $fraction)) : null,
            scoreThreshold: $integer($input['scoreThreshold'] ?? null),
            includeDisqualified: (bool) ($input['includeDisqualified'] ?? false),
        );
    }

    /** Whether any filter is set; empty criteria advance everything. */
    public function isEmpty(): bool
    {
        return $this->minAverageScore === null
            && $this->minVoteCount === null
            && $this->topN === null
            && $this->minAcceptCount === null
            && $this->minVoterFraction === null;
    }

    /** Whether the proportional rule is usable as configured. */
    public function hasVoterFractionRule(): bool
    {
        return $this->minVoterFraction !== null && $this->minVoterFraction > 0;
    }

    /**
     * The score a vote must reach to count towards the fraction.
     *
     * Defaults sensibly per method when the caller does not say: an accept
     * for yes/no, and the upper half of the scale for a rating round.
     */
    public function effectiveScoreThreshold(VotingMethod $method, int $maxRating): int
    {
        if ($this->scoreThreshold !== null) {
            return $this->scoreThreshold;
        }

        return $method === VotingMethod::Rating
            ? (int) ceil($maxRating / 2) + 1
            : 1;
    }

    /**
     * Plain-language description of the criteria, stored on the derived
     * round so coordinators can see later how its shortlist was chosen.
     */
    public function describe(VotingMethod $method, int $maxRating = 5): string
    {
        $parts = [];

        if ($this->hasVoterFractionRule()) {
            $parts[] = sprintf(
                '%d%% of jurors scored it %d or above',
                (int) round($this->minVoterFraction * 100),
                $this->effectiveScoreThreshold($method, $maxRating),
            );
        }

        if ($this->minAcceptCount !== null) {
            $parts[] = sprintf('at least %d accept vote(s)', $this->minAcceptCount);
        }

        if ($this->minAverageScore !== null) {
            $parts[] = sprintf(
                '%s %s %s',
                $method === VotingMethod::RankOrder ? 'average rank' : 'average score',
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
