<?php

declare(strict_types=1);

namespace JuryTool\Tests\Unit;

use JuryTool\Domain\Enum\VotingMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The default progression a derived round starts with.
 *
 * derive() used to always copy the source round's own voting method, so a
 * Yes/No round could only ever spawn another Yes/No round — a contest
 * meant to narrow through Yes/No, then Rating, then Ranking had no way to
 * get past the first step without the coordinator manually changing the
 * method by hand on every round. This is only a starting suggestion; the
 * coordinator can still pick a different method before activating.
 */
class VotingMethodFunnelTest extends TestCase
{
    #[Test]
    public function yes_no_narrows_to_rating(): void
    {
        self::assertSame(VotingMethod::Rating, VotingMethod::YesNo->nextInFunnel());
    }

    #[Test]
    public function rating_narrows_to_ranking(): void
    {
        self::assertSame(VotingMethod::RankOrder, VotingMethod::Rating->nextInFunnel());
    }

    /**
     * Ranking's own successor is the jury meeting, but that is opened
     * through its own dedicated action (createMeeting), not derive() — a
     * round derived from a ranking round defaults to ranking again on
     * whatever smaller set carried forward.
     */
    #[Test]
    public function ranking_repeats_itself(): void
    {
        self::assertSame(VotingMethod::RankOrder, VotingMethod::RankOrder->nextInFunnel());
    }

    /** The meeting is the last step; nothing derives from it at all. */
    #[Test]
    public function meeting_has_no_further_step(): void
    {
        self::assertSame(VotingMethod::Meeting, VotingMethod::Meeting->nextInFunnel());
    }
}
