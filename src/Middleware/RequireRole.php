<?php

declare(strict_types=1);

namespace JuryTool\Middleware;

use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Service\AccessControl;
use JuryTool\Support\DomainException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Requires that the user holds a role *somewhere*, as a coarse gate.
 *
 * Real authority is scoped to a project or campaign, so this cannot be the
 * whole check: it only keeps signed-out users and people with no standing
 * at all off a route. The action itself asks AccessControl whether this
 * person may act on *this* project or campaign.
 *
 * The distinction matters because roles are not exclusive. A lead or an
 * organizer frequently also judges rounds, and someone who merely judges
 * one campaign may lead another — so a route open to jurors must not
 * exclude a lead, and vice versa.
 */
class RequireRole implements MiddlewareInterface
{
    public function __construct(
        private readonly UserRole $required,
        private readonly AccessControl $access,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        if (!$user instanceof User) {
            throw DomainException::unauthorized();
        }

        if (!$this->holdsRequiredRole($user)) {
            throw DomainException::forbidden(
                sprintf('This action requires the %s role.', $this->required->value)
            );
        }

        return $handler->handle($request);
    }

    /**
     * Whether the user holds the required role, or anything above it, in
     * any scope at all.
     *
     * Both the scoped grants and the baseline column are consulted: a juror
     * invited to a round has a RoundJuror seat rather than a grant, and
     * their baseline is what records that they may judge.
     */
    private function holdsRequiredRole(User $user): bool
    {
        if ($user->getRole()->covers($this->required)) {
            return true;
        }

        foreach ($this->access->assignmentsFor($user) as $assignment) {
            if ($assignment->getRole()->covers($this->required)) {
                return true;
            }
        }

        return false;
    }

    public static function admin(AccessControl $access): self
    {
        return new self(UserRole::Admin, $access);
    }

    public static function lead(AccessControl $access): self
    {
        return new self(UserRole::Lead, $access);
    }

    public static function organizer(AccessControl $access): self
    {
        return new self(UserRole::Organizer, $access);
    }

    public static function jury(AccessControl $access): self
    {
        return new self(UserRole::Jury, $access);
    }
}
