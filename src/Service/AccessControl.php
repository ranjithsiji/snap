<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\Project;
use JuryTool\Domain\Entity\RoleAssignment;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Support\DomainException;

/**
 * Answers "may this person do that here?".
 *
 * Roles are scoped, so the question is always about a particular project or
 * campaign rather than the tool as a whole: leading Wiki Loves Folklore
 * confers nothing over Wiki Loves Earth.
 */
class AccessControl
{
    /** @var array<int, list<RoleAssignment>> cached per user for one request */
    private array $cache = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Every grant this user holds.
     *
     * @return list<RoleAssignment>
     */
    public function assignmentsFor(User $user): array
    {
        $id = (int) $user->getId();

        return $this->cache[$id] ??= $this->em->getRepository(RoleAssignment::class)
            ->findBy(['user' => $user]);
    }

    public function isAdmin(User $user): bool
    {
        foreach ($this->assignmentsFor($user) as $assignment) {
            if ($assignment->getRole() === UserRole::Admin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every project this user leads.
     *
     * A person can lead more than one — a Wikimedia chapter organizing
     * both Wiki Loves Earth and Wiki Loves Monuments in the same year is
     * commonly the same small group of people running both.
     *
     * @return list<Project>
     */
    public function ledProjects(User $user): array
    {
        $projects = [];

        foreach ($this->assignmentsFor($user) as $assignment) {
            if ($assignment->getRole() === UserRole::Lead && $assignment->getProject() !== null) {
                $projects[] = $assignment->getProject();
            }
        }

        return $projects;
    }

    public function leads(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        foreach ($this->ledProjects($user) as $led) {
            if ($led->getId() === $project->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user may organize this campaign — either as its lead's
     * appointee, or as the lead of the project it belongs to.
     */
    public function organizes(User $user, Campaign $campaign): bool
    {
        foreach ($this->assignmentsFor($user) as $assignment) {
            if (
                in_array($assignment->getRole(), [UserRole::Admin, UserRole::Lead, UserRole::Organizer], true)
                && $assignment->coversCampaign($campaign)
            ) {
                return true;
            }
        }

        return false;
    }

    /** Campaigns the user may organize, for listings. */
    public function organizedCampaignIds(User $user): array
    {
        $ids = [];

        foreach ($this->assignmentsFor($user) as $assignment) {
            if ($assignment->getCampaign() !== null) {
                $ids[] = (int) $assignment->getCampaign()->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    /** The highest role this user holds anywhere, for display. */
    public function highestRole(User $user): UserRole
    {
        $highest = UserRole::Jury;

        foreach ($this->assignmentsFor($user) as $assignment) {
            if ($assignment->getRole()->level() > $highest->level()) {
                $highest = $assignment->getRole();
            }
        }

        return $highest;
    }

    /**
     * Everything this user has been trusted with, across every edition.
     *
     * People move between roles as contests repeat — a 2025 juror may
     * organize in 2026 and lead in 2027 — and they keep the same account
     * throughout. Grants accumulate rather than replace one another, so
     * this reads as a career rather than a single current title, and old
     * votes stay attributed to the person who cast them.
     *
     * @return list<array<string, mixed>>
     */
    public function roleHistory(User $user): array
    {
        $history = [];

        foreach ($this->assignmentsFor($user) as $assignment) {
            $history[] = [
                // Needed to revoke a specific grant: this method's only
                // caller until now displayed the history but never acted
                // on it, so a payload with no id went unnoticed.
                'id' => $assignment->getId(),
                'role' => $assignment->getRole()->value,
                'roleLabel' => $assignment->getRole()->label(),
                'scope' => $assignment->scopeLabel(),
                'projectId' => $assignment->getProject()?->getId(),
                'projectName' => $assignment->getProject()?->getName(),
                'campaignId' => $assignment->getCampaign()?->getId(),
                'campaignName' => $assignment->getCampaign()?->getName(),
                'grantedBy' => $assignment->getGrantedBy(),
                'grantedAt' => $assignment->getGrantedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        // Most senior first, then most recent, so the current standing
        // reads off the top of the list.
        usort($history, static function (array $a, array $b): int {
            $rank = static fn (string $role): int => UserRole::from($role)->level();

            return $rank($b['role']) <=> $rank($a['role'])
                ?: strcmp($b['grantedAt'], $a['grantedAt']);
        });

        return $history;
    }

    // --- Guards -------------------------------------------------------
    //
    // These throw rather than return false, so an action can state its
    // requirement in one line and let the error middleware render it.

    public function requireAdmin(User $user): void
    {
        if (!$this->isAdmin($user)) {
            throw DomainException::forbidden('Only an admin can do that.');
        }
    }

    public function requireLead(User $user, Project $project): void
    {
        if (!$this->leads($user, $project)) {
            throw DomainException::forbidden(
                sprintf('Only the lead of %s can do that.', $project->getName())
            );
        }
    }

    public function requireOrganizer(User $user, Campaign $campaign): void
    {
        if (!$this->organizes($user, $campaign)) {
            throw DomainException::forbidden(
                sprintf('You do not help run %s.', $campaign->getName())
            );
        }
    }

    public function requireRoundAccess(User $user, Round $round): void
    {
        $this->requireOrganizer($user, $round->getCampaign());
    }

    /**
     * Appoints a lead. A person may lead more than one project — the
     * unique constraint on RoleAssignment is (user, role, project), so
     * only the exact same grant twice is actually blocked; leading a
     * second, different project has never been a database-level problem.
     */
    public function appointLead(User $user, Project $project, ?User $grantedBy = null): RoleAssignment
    {
        $duplicate = $this->em->getRepository(RoleAssignment::class)->findOneBy([
            'user' => $user,
            'role' => UserRole::Lead,
            'project' => $project,
        ]);

        if ($duplicate !== null) {
            throw DomainException::badRequest(
                sprintf('%s already leads this project.', $user->getUsername())
            );
        }

        $assignment = RoleAssignment::lead($user, $project, $grantedBy);

        $this->em->persist($assignment);
        $this->em->flush();
        $this->forget($user);

        return $assignment;
    }

    /** Appoints an organizer to help run one campaign. */
    public function appointOrganizer(User $user, Campaign $campaign, ?User $grantedBy = null): RoleAssignment
    {
        $existing = $this->em->getRepository(RoleAssignment::class)->findOneBy([
            'user' => $user,
            'role' => UserRole::Organizer,
            'campaign' => $campaign,
        ]);

        if ($existing !== null) {
            return $existing;
        }

        $assignment = RoleAssignment::organizer($user, $campaign, $grantedBy);

        $this->em->persist($assignment);
        $this->em->flush();
        $this->forget($user);

        return $assignment;
    }

    /**
     * Withdraws a grant.
     *
     * Only the grant is removed. The account stays active and keeps every
     * other seat it holds — standing down from a role is not a sanction,
     * and losing a project lead must never cost someone their login.
     */
    public function revoke(RoleAssignment $assignment): void
    {
        $user = $assignment->getUser();

        $this->em->remove($assignment);
        $this->em->flush();
        $this->forget($user);
    }

    /**
     * Recalculates a user's baseline role from the grants they still hold.
     *
     * Leads change between editions of a contest, so someone who stood down
     * should stop seeing lead-only screens — but they remain a full user
     * with whatever seats they still have, and are free to lead a different
     * project later. Their account is never touched.
     */
    public function syncBaselineRole(User $user): UserRole
    {
        $highest = $this->highestRole($user);

        // A juror seat is not a RoleAssignment, so someone with no grants
        // at all still keeps Jury as their floor.
        if ($highest->level() < UserRole::Jury->level()) {
            $highest = UserRole::Jury;
        }

        if ($user->getRole() !== $highest) {
            $user->setRole($highest);
            $this->em->flush();
        }

        return $highest;
    }

    /** Drops the per-request cache after a grant changes. */
    private function forget(User $user): void
    {
        unset($this->cache[(int) $user->getId()]);
    }
}
