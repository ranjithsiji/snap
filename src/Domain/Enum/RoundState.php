<?php

declare(strict_types=1);

namespace JuryTool\Domain\Enum;

/**
 * Lifecycle of a round.
 *
 * Draft is the pre-import state. Activate moves a round to Active; a
 * coordinator can Pause it to suspend voting without losing progress, and
 * Finalize freezes it for good so results can be exported and a following
 * round derived from it.
 */
enum RoundState: string
{
    /** Created and configured; images may still be importing. */
    case Draft = 'draft';

    /** Jurors may vote. */
    case Active = 'active';

    /** Temporarily suspended by a coordinator; votes retained, none accepted. */
    case Paused = 'paused';

    /** Voting closed for good; results readable, votes frozen. */
    case Finalized = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Active => 'active',
            self::Paused => 'paused',
            self::Finalized => 'finalized',
        };
    }

    public function acceptsVotes(): bool
    {
        return $this === self::Active;
    }

    /** Once finalized a round is immutable; used to guard edits and re-imports. */
    public function isFinal(): bool
    {
        return $this === self::Finalized;
    }

    /**
     * Whether a transition to the given state is allowed.
     *
     * Draft can only be activated. Active and Paused toggle between each
     * other and may both be finalized. Finalized is terminal.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::Active,
            self::Active => in_array($target, [self::Paused, self::Finalized], true),
            self::Paused => in_array($target, [self::Active, self::Finalized], true),
            self::Finalized => false,
        };
    }
}
