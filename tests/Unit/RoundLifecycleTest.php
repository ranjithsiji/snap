<?php

declare(strict_types=1);

namespace JuryTool\Tests\Unit;

use JuryTool\Domain\Enum\RoundState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules that end a round.
 *
 * Finalizing is the point of no return: judging stops, and only then can
 * the next round be built from the result. Both halves are enforced in
 * separate places — the state machine here, and a guard in
 * RoundDerivationService — so this pins the half that everything else
 * reads from.
 */
class RoundLifecycleTest extends TestCase
{
    #[Test]
    public function finalizedIsTerminal(): void
    {
        foreach (RoundState::cases() as $target) {
            self::assertFalse(
                RoundState::Finalized->canTransitionTo($target),
                sprintf('A finalized round must not become %s.', $target->value),
            );
        }
    }

    /**
     * The one that stops judging: acceptsVotes is what castVote and the
     * juror's queue both consult.
     */
    #[Test]
    public function onlyAnActiveRoundAcceptsVotes(): void
    {
        self::assertTrue(RoundState::Active->acceptsVotes());

        self::assertFalse(RoundState::Draft->acceptsVotes());
        self::assertFalse(RoundState::Paused->acceptsVotes());
        self::assertFalse(RoundState::Finalized->acceptsVotes());
    }

    #[Test]
    public function aRoundCanBeFinalizedFromActiveOrPaused(): void
    {
        self::assertTrue(RoundState::Active->canTransitionTo(RoundState::Finalized));
        self::assertTrue(RoundState::Paused->canTransitionTo(RoundState::Finalized));

        // A draft round has judged nothing, so there is no result to fix.
        self::assertFalse(RoundState::Draft->canTransitionTo(RoundState::Finalized));
    }

    #[Test]
    public function aDraftRoundCanOnlyBeActivated(): void
    {
        self::assertTrue(RoundState::Draft->canTransitionTo(RoundState::Active));
        self::assertFalse(RoundState::Draft->canTransitionTo(RoundState::Paused));
    }

    #[Test]
    public function activeAndPausedToggle(): void
    {
        self::assertTrue(RoundState::Active->canTransitionTo(RoundState::Paused));
        self::assertTrue(RoundState::Paused->canTransitionTo(RoundState::Active));
    }
}
