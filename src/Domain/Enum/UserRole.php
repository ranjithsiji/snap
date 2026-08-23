<?php

declare(strict_types=1);

namespace JuryTool\Domain\Enum;

/**
 * What a person may do in the tool.
 *
 * The hierarchy mirrors how Wiki Loves contests are actually run:
 *
 *   Project    Wiki Loves Folklore          — created by an Admin
 *     Campaign   Wiki Loves Folklore 2026   — created by that project's Lead
 *       Round      Round 1, yes/no          — created by an Organizer
 *
 * Only Admin is global. Lead is held for one project, Organizer for the
 * campaigns they are assigned to, and Jury is a seat on a specific round —
 * so holding a role here does not by itself grant access to everything of
 * that kind. See ProjectLead, CampaignOrganizer and RoundJuror.
 */
enum UserRole: string
{
    /**
     * System administrator. Creates projects and appoints their leads.
     * The only role whose authority is global.
     */
    case Admin = 'admin';

    /**
     * Leads one project: creates its campaigns and appoints organizers to
     * help run them. A person may lead only one project at a time.
     */
    case Lead = 'lead';

    /**
     * Helps a lead run a campaign: creates rounds, manages the jury and
     * reads results.
     */
    case Organizer = 'organizer';

    /** Judges the rounds they hold a seat on. */
    case Jury = 'jury';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Lead => 'Lead',
            self::Organizer => 'Organizer',
            self::Jury => 'Jury',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Creates projects and appoints leads.',
            self::Lead => 'Leads one project and creates its campaigns.',
            self::Organizer => 'Creates rounds and manages the jury.',
            self::Jury => 'Judges media in rounds they are invited to.',
        };
    }

    /**
     * Roles implied by holding this one.
     *
     * Each level can do everything the level below it can, which is what
     * lets a lead step in and configure a round themselves.
     *
     * @return list<self>
     */
    public function impliedRoles(): array
    {
        return match ($this) {
            self::Admin => [self::Admin, self::Lead, self::Organizer, self::Jury],
            self::Lead => [self::Lead, self::Organizer, self::Jury],
            self::Organizer => [self::Organizer, self::Jury],
            self::Jury => [self::Jury],
        };
    }

    public function covers(self $other): bool
    {
        return in_array($other, $this->impliedRoles(), true);
    }

    /** Rank, for comparing seniority without listing the cases again. */
    public function level(): int
    {
        return match ($this) {
            self::Admin => 4,
            self::Lead => 3,
            self::Organizer => 2,
            self::Jury => 1,
        };
    }
}
