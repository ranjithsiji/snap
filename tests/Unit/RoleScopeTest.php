<?php

declare(strict_types=1);

namespace JuryTool\Tests\Unit;

use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\Project;
use JuryTool\Domain\Entity\RoleAssignment;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Service\AccessControl;
use JuryTool\Support\DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which campaigns a grant actually reaches.
 *
 * Every round guard in RoundActions ends at coversCampaign(), so this is
 * the one decision that separates "organizes this contest" from "holds an
 * organizer role somewhere in the tool". The route middleware only knows
 * the second, which is why the actions must ask this question again — an
 * organizer of Wiki Loves Earth must not be able to touch Wiki Loves
 * Folklore's rounds.
 */
class RoleScopeTest extends TestCase
{
    /**
     * Entities carry no id until they are persisted, and coversCampaign()
     * compares by id — so the scoping it does is only meaningful once they
     * have one. Assigned directly rather than through the database, which
     * these tests deliberately do not need.
     */
    private static function withId(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);

        return $entity;
    }

    private static function project(int $id, string $name): Project
    {
        return self::withId(new Project($name, strtolower($name)), $id);
    }

    private static function campaign(int $id, Project $project, string $name): Campaign
    {
        return self::withId(new Campaign($project, $name, strtolower($name)), $id);
    }

    /** A round is only ever reached through its campaign, which is what the guards read. */
    private static function roundIn(Campaign $campaign): Round
    {
        return new Round($campaign, 'Round 1');
    }

    #[Test]
    public function anOrganizerReachesOnlyTheCampaignTheyWereAppointedTo(): void
    {
        $project = self::project(1, 'Earth');
        $mine = self::campaign(10, $project, 'Earth 2026');
        $theirs = self::campaign(11, $project, 'Earth 2027');

        $grant = RoleAssignment::organizer(new User('Ida', UserRole::Organizer), $mine);

        self::assertTrue($grant->coversCampaign($mine));
        self::assertFalse(
            $grant->coversCampaign($theirs),
            'An organizer grant must not reach a sibling campaign in the same project.',
        );
    }

    /**
     * The case the screen in question turns on: a lead manages the rounds
     * of every campaign in their project without being appointed to each
     * one separately.
     */
    #[Test]
    public function aLeadReachesEveryCampaignInTheirProject(): void
    {
        $project = self::project(1, 'Earth');
        $grant = RoleAssignment::lead(new User('Lea', UserRole::Lead), $project);

        self::assertTrue($grant->coversCampaign(self::campaign(10, $project, 'Earth 2026')));
        self::assertTrue($grant->coversCampaign(self::campaign(11, $project, 'Earth 2027')));
    }

    #[Test]
    public function aLeadReachesNothingInAnotherProject(): void
    {
        $grant = RoleAssignment::lead(new User('Lea', UserRole::Lead), self::project(1, 'Earth'));
        $elsewhere = self::campaign(20, self::project(2, 'Folklore'), 'Folklore 2026');

        self::assertFalse($grant->coversCampaign($elsewhere));
    }

    /** Admin is the one grant with no scope to compare against. */
    #[Test]
    public function anAdminReachesEveryCampaign(): void
    {
        $grant = RoleAssignment::admin(new User('Ada', UserRole::Admin));

        self::assertTrue($grant->coversCampaign(
            self::campaign(20, self::project(2, 'Folklore'), 'Folklore 2026')
        ));
    }

    /**
     * An organizer grant records the project too, so project-wide queries
     * need no join. That column must not widen what the grant covers.
     */
    #[Test]
    public function anOrganizersRecordedProjectDoesNotWidenTheirScope(): void
    {
        $project = self::project(1, 'Earth');
        $grant = RoleAssignment::organizer(
            new User('Ida', UserRole::Organizer),
            self::campaign(10, $project, 'Earth 2026'),
        );

        self::assertSame($project, $grant->getProject());
        self::assertFalse($grant->coversCampaign(self::campaign(11, $project, 'Earth 2027')));
    }

    /**
     * An AccessControl whose only grants are the ones given, so the guards
     * can be exercised without a database behind them.
     *
     * @param list<RoleAssignment> $grants
     */
    private function accessControlWith(array $grants): AccessControl
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($grants);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new AccessControl($em);
    }

    /**
     * The whole point of the role: an organizer runs every round in the
     * campaign they were appointed to.
     *
     * Each of create, read, update and delete in RoundActions ends at one
     * of these two guards — create asks requireOrganizer about the campaign
     * directly, the rest go through requireRoundAccess — so an organizer
     * passing both is what CRUD over the campaign's rounds amounts to.
     */
    #[Test]
    public function anOrganizerHasFullControlOfTheirCampaignsRounds(): void
    {
        $organizer = new User('Ida', UserRole::Organizer);
        $campaign = self::campaign(10, self::project(1, 'Earth'), 'Earth 2026');

        $access = $this->accessControlWith([
            RoleAssignment::organizer($organizer, $campaign),
        ]);

        self::assertTrue($access->organizes($organizer, $campaign));

        // Guards throw rather than return, so reaching the line after them
        // is the pass.
        $access->requireOrganizer($organizer, $campaign);
        $access->requireRoundAccess($organizer, self::roundIn($campaign));

        self::assertTrue(true, 'Neither guard refused an organizer of this campaign.');
    }

    /** The same guards, on a campaign they were not appointed to. */
    #[Test]
    public function anOrganizerHasNoControlOfAnotherCampaignsRounds(): void
    {
        $organizer = new User('Ida', UserRole::Organizer);
        $mine = self::campaign(10, self::project(1, 'Earth'), 'Earth 2026');
        $theirs = self::campaign(20, self::project(2, 'Folklore'), 'Folklore 2026');

        $access = $this->accessControlWith([
            RoleAssignment::organizer($organizer, $mine),
        ]);

        self::assertFalse($access->organizes($organizer, $theirs));

        $this->expectException(DomainException::class);
        $access->requireRoundAccess($organizer, self::roundIn($theirs));
    }

    /** A lead reaches the rounds of every campaign in their project. */
    #[Test]
    public function aLeadHasTheSameControlOverEveryCampaignInTheProject(): void
    {
        $project = self::project(1, 'Earth');
        $lead = new User('Lea', UserRole::Lead);

        $access = $this->accessControlWith([RoleAssignment::lead($lead, $project)]);

        $access->requireRoundAccess($lead, self::roundIn(self::campaign(10, $project, 'Earth 2026')));
        $access->requireRoundAccess($lead, self::roundIn(self::campaign(11, $project, 'Earth 2027')));

        self::assertTrue(true, 'A lead was refused a round in their own project.');
    }
}
