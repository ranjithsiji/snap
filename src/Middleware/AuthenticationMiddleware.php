<?php

declare(strict_types=1);

namespace JuryTool\Middleware;

use JuryTool\Service\AuthService;
use JuryTool\Service\TokenService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Resolves the session token into a User and attaches it to the request.
 *
 * Runs on every route and never rejects: authorisation is the job of
 * RequireRole, so public endpoints simply see a null user.
 */
class AuthenticationMiddleware implements MiddlewareInterface
{
    public const USER_ATTRIBUTE = 'user';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuthService $auth,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $token = $this->extractToken($request);

        if ($token !== null) {
            $claims = $this->tokens->verify($token);

            if ($claims !== null) {
                $user = $this->auth->userFromClaims($claims);

                if ($user !== null) {
                    $request = $request->withAttribute(self::USER_ATTRIBUTE, $user);
                }
            }
        }

        return $handler->handle($request);
    }

    /**
     * Reads the token from the session cookie, falling back to a bearer
     * header so the API stays usable from scripts and tests.
     */
    private function extractToken(Request $request): ?string
    {
        $cookies = $request->getCookieParams();
        $cookieName = $this->tokens->cookieName();

        if (!empty($cookies[$cookieName]) && is_string($cookies[$cookieName])) {
            return $cookies[$cookieName];
        }

        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
