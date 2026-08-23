<?php

declare(strict_types=1);

namespace JuryTool\Domain\Enum;

/**
 * Roles a Commons user may hold with respect to a campaign.
 *
 * These drive the round's "disqualify …" rules: an image uploaded by
 * someone holding a disqualified role is excluded from that round, which is
 * how a contest keeps its own organizers and jurors from competing in it.
 *
 * Kept separate from UserRole because most of these people never log into
 * the tool — their uploads still need excluding either way.
 */
enum ParticipantRole: string
{
    /** Sits on a jury panel in this campaign. */
    case Juror = 'juror';

    /** Runs the contest and can see jury results. */
    case Organizer = 'organizer';

    /** Coordinates a round or a regional part of the contest. */
    case Coordinator = 'coordinator';

    /** Maintains the tooling or the contest infrastructure. */
    case Maintainer = 'maintainer';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
