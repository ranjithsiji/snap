<?php

declare(strict_types=1);

namespace JuryTool\Middleware;

use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Support\DomainException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Rejects requests from users who lack the required role.
 *
 * Attach per route group; AuthenticationMiddleware must have run first.
 */
class RequireRole implements MiddlewareInterface
{
    public function __construct(private readonly UserRole $required)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        if (!$user instanceof User) {
            throw DomainException::unauthorized();
        }

        if (!$user->hasRole($this->required)) {
            throw DomainException::forbidden(
                sprintf('This action requires the %s role.', $this->required->value)
            );
        }

        return $handler->handle($request);
    }

    public static function administrator(): self
    {
        return new self(UserRole::Administrator);
    }

    public static function organizer(): self
    {
        return new self(UserRole::Organizer);
    }

    public static function juror(): self
    {
        return new self(UserRole::Juror);
    }
}
