<?php

declare(strict_types=1);

namespace JuryTool\Support;

use RuntimeException;

/**
 * A rule violation the user can act on — voting after a deadline, an
 * out-of-range score, an unauthorised action.
 *
 * The error middleware renders these as 4xx with the message shown to the
 * user, unlike unexpected exceptions which become an opaque 500.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public static function notFound(string $what): self
    {
        return new self("$what not found.", 404);
    }

    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return new self($message, 403);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self($message, 401);
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }
}
