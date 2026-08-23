<?php

declare(strict_types=1);

namespace JuryTool\Domain\Enum;

/**
 * How jurors express a judgement on an image within a round.
 */
enum VotingMethod: string
{
    /** Accept or reject. Stored as score 0 or 1. */
    case YesNo = 'yesno';

    /** A star rating within the round's configured range. */
    case Rating = 'rating';

    /** Jurors order images relative to one another; score holds the rank. */
    case RankOrder = 'rank';

    /**
     * A final jury meeting: the panel discusses the shortlist together and
     * agrees one shared ranking, rather than voting independently.
     */
    case Meeting = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::YesNo => 'Yes / No',
            self::Rating => 'Rating',
            self::RankOrder => 'Rank order',
            self::Meeting => 'Final jury meeting',
        };
    }

    /** Whether judgements are cast privately, one juror at a time. */
    public function isIndependent(): bool
    {
        return $this !== self::Meeting;
    }

    /**
     * Lowest and highest score accepted for this method.
     *
     * For rating the ceiling is round-configurable, so the round's own
     * maxRating wins; the value here is only the fallback default.
     *
     * @return array{int, int}
     */
    public function defaultRange(): array
    {
        return match ($this) {
            self::YesNo => [0, 1],
            self::Rating => [1, 5],
            // Rank is bounded by the size of the juror's assigned set, which
            // is not knowable here; validation happens in the vote service.
            self::RankOrder => [1, PHP_INT_MAX],
        };
    }

    /** Whether a higher score means a better image. */
    public function higherIsBetter(): bool
    {
        return $this !== self::RankOrder;
    }
}
