<?php

declare(strict_types=1);

namespace JuryTool\Domain\Enum;

/**
 * What a person may do in the tool as a whole.
 *
 * Round-level participation is separate: being a juror on a round is a
 * seat (see RoundJuror), and a seat can be handed to a different user
 * without touching these global roles.
 */
enum UserRole: string
{
    /**
     * Creates campaigns and rounds, and grants access to others. At least
     * one person from the organizing team holds this.
     */
    case Administrator = 'administrator';

    /**
     * Sees the results of the jury selection, including comments and
     * per-juror breakdowns. Cannot change a round's configuration or vote.
     */
    case Organizer = 'organizer';

    /** Rates and rejects pictures and leaves comments on rounds they sit on. */
    case Juror = 'juror';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Organizer => 'Organizer',
            self::Juror => 'Juror',
        };
    }

    /**
     * Roles implied by holding this one.
     *
     * An administrator can do everything. An organizer only reads results —
     * they are deliberately not a juror, since seeing every vote while
     * casting your own would undermine the selection.
     *
     * @return list<self>
     */
    public function impliedRoles(): array
    {
        return match ($this) {
            self::Administrator => [self::Administrator, self::Organizer, self::Juror],
            self::Organizer => [self::Organizer],
            self::Juror => [self::Juror],
        };
    }

    public function covers(self $other): bool
    {
        return in_array($other, $this->impliedRoles(), true);
    }
}
