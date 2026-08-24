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

    /**
     * The usual next step for a round derived from one judged this way.
     *
     * A contest narrows as it goes: Yes/No is the pre-jury pass that cuts
     * a large pool down to a shortlist, Rating and Ranking each narrow it
     * further and ask more of the jury per image, and the meeting is
     * where the panel settles the result together. This is only ever a
     * starting suggestion for a newly derived round — the coordinator can
     * still pick a different method for it before activating.
     */
    public function nextInFunnel(): self
    {
        return match ($this) {
            self::YesNo => self::Rating,
            self::Rating => self::RankOrder,
            // Ranking's own next step is the meeting, but that is opened
            // through its own dedicated action rather than derive() — a
            // derived round defaults to repeating ranking on a smaller set.
            self::RankOrder => self::RankOrder,
            self::Meeting => self::Meeting,
        };
    }
}
