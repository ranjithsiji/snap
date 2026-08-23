<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Project;
use JuryTool\Domain\Entity\RoleAssignment;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Service\AccessControl;
use JuryTool\Service\ActivityLogger;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Projects — the contest families, such as Wiki Loves Folklore.
 *
 * Admins create them and appoint leads; leads run everything beneath.
 */
class ProjectActions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessControl $access,
        private readonly ActivityLogger $log,
    ) {
    }

    /**
     * Projects visible to this user.
     *
     * Admins see everything; everyone else sees the projects they have a
     * part in, so a juror's home screen is not filled with contests they
     * have nothing to do with.
     */
    public function list(Request $request, Response $response): Response
    {
        $user = $this->user($request);

        $projects = $this->access->isAdmin($user)
            ? $this->em->getRepository(Project::class)->findBy([], ['name' => 'ASC'])
            : $this->visibleProjects($user);

        return Json::write($response, [
            'projects' => array_map(
                fn (Project $p): array => Presenter::project($p) + [
                    'canManage' => $this->access->leads($user, $p),
                ],
                $projects,
            ),
            'canCreate' => $this->access->isAdmin($user),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $project = $this->find($args['id']);

        return Json::write($response, [
            'project' => Presenter::project($project, withCampaigns: true),
            'leads' => $this->leadDetails($project),
            'canManage' => $this->access->leads($user, $project),
            'canAppointLead' => $this->access->isAdmin($user),
        ]);
    }

    /** Creates a project. Admins only. */
    public function create(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        $this->access->requireAdmin($user);

        $body = Json::body($request);
        $name = Json::requireString($body, 'name');
        $slug = Json::optionalString($body, 'slug') ?? $this->slugify($name);

        if ($this->em->getRepository(Project::class)->findOneBy(['slug' => $slug]) !== null) {
            throw DomainException::badRequest("A project with the slug '$slug' already exists.");
        }

        $project = new Project($name, $slug);
        $project->setDescription(Json::optionalString($body, 'description'));
        $project->setHomepageUrl(Json::optionalString($body, 'homepageUrl'));

        $this->em->persist($project);
        $this->em->flush();

        // A project without a lead cannot do anything, so the creating
        // admin can name one in the same request.
        $leadName = Json::optionalString($body, 'lead');

        if ($leadName !== null) {
            $this->access->appointLead($this->resolveUser($leadName), $project, $user);
        }

        $this->log->record(
            $user,
            'project.create',
            sprintf('Created project "%s"', $project->getName()),
            'Project',
            $project->getId(),
            request: $request,
        );

        return Json::write($response, [
            'project' => Presenter::project($project),
            'leads' => $this->leadDetails($project),
        ], 201);
    }

    /** Edits a project. Its lead may do this, as may an admin. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $project = $this->find($args['id']);

        $this->access->requireLead($user, $project);

        $body = Json::body($request);

        if (($name = Json::optionalString($body, 'name')) !== null) {
            $project->setName($name);
        }

        if (array_key_exists('description', $body)) {
            $project->setDescription(Json::optionalString($body, 'description'));
        }

        if (array_key_exists('homepageUrl', $body)) {
            $project->setHomepageUrl(Json::optionalString($body, 'homepageUrl'));
        }

        if (array_key_exists('isArchived', $body)) {
            $project->setArchived(Json::bool($body, 'isArchived'));
        }

        $this->em->flush();

        return Json::write($response, ['project' => Presenter::project($project)]);
    }

    /**
     * Appoints a lead. Admins only.
     *
     * A person may lead only one project, so this fails with a clear
     * message if they already lead another.
     */
    public function appointLead(Request $request, Response $response, array $args): Response
    {
        $actor = $this->user($request);
        $this->access->requireAdmin($actor);

        $project = $this->find($args['id']);
        $lead = $this->resolveUser(Json::requireString(Json::body($request), 'username'));

        $this->access->appointLead($lead, $project, $actor);

        // Leading a project is a step up for anyone currently below it.
        if ($lead->getRole()->level() < UserRole::Lead->level()) {
            $lead->setRole(UserRole::Lead);
            $this->em->flush();
        }

        $this->log->record(
            $actor,
            'project.appoint_lead',
            sprintf('Appointed %s to lead "%s"', $lead->getUsername(), $project->getName()),
            'Project',
            $project->getId(),
            request: $request,
        );

        return Json::write($response, ['leads' => $this->leadDetails($project)]);
    }

    /** Removes a lead. Admins only. */
    public function removeLead(Request $request, Response $response, array $args): Response
    {
        $actor = $this->user($request);
        $this->access->requireAdmin($actor);

        $project = $this->find($args['id']);

        $assignment = $this->em->getRepository(RoleAssignment::class)->findOneBy([
            'project' => $project,
            'role' => UserRole::Lead,
            'user' => (int) $args['userId'],
        ]);

        if ($assignment === null) {
            throw DomainException::notFound('Lead');
        }

        $formerLead = $assignment->getUser();
        $username = $formerLead->getUsername();

        $this->access->revoke($assignment);

        // Standing down is not a sanction: the account stays active and the
        // person keeps every other seat they hold. Only the baseline role is
        // recalculated, so the menu stops offering lead-only screens they
        // can no longer use. Leads change between editions — the same person
        // is free to lead a different project next year.
        $this->access->syncBaselineRole($formerLead);

        $this->log->record(
            $actor,
            'project.remove_lead',
            sprintf('Removed %s as lead of "%s"', $username, $project->getName()),
            'Project',
            $project->getId(),
            request: $request,
        );

        return Json::write($response, ['leads' => $this->leadDetails($project)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $this->access->requireAdmin($user);

        $project = $this->find($args['id']);

        if (!$project->getCampaigns()->isEmpty()) {
            throw DomainException::badRequest(
                'This project still has campaigns. Delete or archive them first.'
            );
        }

        $name = $project->getName();
        $id = $project->getId();

        $this->em->remove($project);
        $this->em->flush();

        $this->log->record(
            $user,
            'project.delete',
            sprintf('Deleted project "%s"', $name),
            'Project',
            $id,
            request: $request,
        );

        return Json::write($response, ['ok' => true]);
    }

    /**
     * Projects this user has any part in — leading one, organizing one of
     * its campaigns, or judging one of its rounds.
     *
     * @return list<Project>
     */
    private function visibleProjects(User $user): array
    {
        $ids = [];

        foreach ($this->access->assignmentsFor($user) as $assignment) {
            if ($assignment->getProject() !== null) {
                $ids[] = (int) $assignment->getProject()->getId();
            }
        }

        // A juror may hold no assignment beyond a round seat, so those are
        // resolved through the round's campaign.
        $rows = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(c.project) AS projectId
             FROM ' . \JuryTool\Domain\Entity\RoundJuror::class . ' j
             JOIN j.round r
             JOIN r.campaign c
             WHERE j.user = :user AND j.isActive = true'
        )->setParameter('user', $user)->getResult();

        foreach ($rows as $row) {
            $ids[] = (int) $row['projectId'];
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return $this->em->getRepository(Project::class)->findBy(['id' => $ids], ['name' => 'ASC']);
    }

    /** @return list<array<string, mixed>> */
    private function leadDetails(Project $project): array
    {
        $assignments = $this->em->getRepository(RoleAssignment::class)->findBy([
            'project' => $project,
            'role' => UserRole::Lead,
        ]);

        return array_map(
            static fn (RoleAssignment $a): array => [
                'userId' => $a->getUser()->getId(),
                'username' => $a->getUser()->getUsername(),
                'appointedBy' => $a->getGrantedBy(),
                'appointedAt' => $a->getGrantedAt()->format(\DateTimeInterface::ATOM),
            ],
            $assignments,
        );
    }

    /** Finds a user by name, or creates the account so they can be invited. */
    private function resolveUser(string $username): User
    {
        $canonical = User::canonicaliseUsername($username);

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $canonical]);

        if ($user === null) {
            // Appointing someone who has not logged in yet is normal: the
            // account binds to their Wikimedia identity on first login.
            $user = new User($canonical, UserRole::Jury);
            $this->em->persist($user);
            $this->em->flush();
        }

        return $user;
    }

    private function user(Request $request): User
    {
        $user = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        if (!$user instanceof User) {
            throw DomainException::unauthorized();
        }

        return $user;
    }

    private function find(string|int $id): Project
    {
        $project = $this->em->getRepository(Project::class)->find((int) $id);

        if ($project === null) {
            throw DomainException::notFound('Project');
        }

        return $project;
    }

    private function slugify(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))) ?? $name;

        return trim($slug, '-') ?: 'project';
    }
}
