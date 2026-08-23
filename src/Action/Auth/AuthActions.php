<?php

declare(strict_types=1);

namespace JuryTool\Action\Auth;

use JuryTool\Domain\Entity\User;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Service\AuthService;
use JuryTool\Service\TokenService;
use JuryTool\Service\WikimediaOAuthService;
use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use JuryTool\Support\Presenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Login, logout and identity endpoints for both authentication paths.
 */
class AuthActions
{
    /** Cookie holding the OAuth state value between redirect and callback. */
    private const STATE_COOKIE = 'jurytool_oauth_state';

    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
        private readonly WikimediaOAuthService $oauth,
        private readonly string $appUrl,
    ) {
    }

    /** Reports who is logged in, and which login methods are available. */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute(AuthenticationMiddleware::USER_ATTRIBUTE);

        return Json::write($response, [
            'user' => $user instanceof User ? Presenter::user($user) : null,
            'methods' => [
                'wikimedia' => $this->oauth->isConfigured(),
                'local' => $this->auth->localLoginEnabled(),
            ],
        ]);
    }

    /** Local username/password login. */
    public function login(Request $request, Response $response): Response
    {
        $body = Json::body($request);

        $user = $this->auth->authenticateLocal(
            Json::requireString($body, 'username'),
            Json::requireString($body, 'password'),
        );

        return $this->withSession(
            Json::write($response, ['user' => Presenter::user($user)]),
            $user,
        );
    }

    /** Starts the Wikimedia OAuth 2.0 flow. */
    public function oauthStart(Request $request, Response $response): Response
    {
        $authorization = $this->oauth->authorizationRequest();

        // The state is held in a short-lived cookie and compared on return,
        // so a callback from a flow this server did not start is rejected.
        return $response
            ->withHeader(
                'Set-Cookie',
                sprintf(
                    '%s=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=600',
                    self::STATE_COOKIE,
                    $authorization['state'],
                ),
            )
            ->withHeader('Location', $authorization['url'])
            ->withStatus(302);
    }

    /** Handles the redirect back from Wikimedia. */
    public function oauthCallback(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();

        if (isset($query['error'])) {
            throw new DomainException(
                'Wikimedia login was cancelled or denied: ' . (string) $query['error'],
                400,
            );
        }

        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;
        $expectedState = $request->getCookieParams()[self::STATE_COOKIE] ?? null;

        if (!is_string($code) || $code === '') {
            throw DomainException::badRequest('Wikimedia did not return an authorization code.');
        }

        if (!is_string($state) || !is_string($expectedState) || !hash_equals($expectedState, $state)) {
            throw DomainException::badRequest('Login state did not match. Please try again.');
        }

        $accessToken = $this->oauth->exchangeCode($code);
        $profile = $this->oauth->fetchProfile($accessToken);

        $user = $this->auth->resolveWikimediaUser(
            $profile['username'],
            $profile['centralAuthId'],
        );

        // Land the user back in the SPA, now carrying a session cookie.
        $redirect = $response
            ->withHeader('Location', $this->appUrl . '/')
            ->withStatus(302);

        return $this->withSession($redirect, $user, clearState: true);
    }

    /** Clears the session cookie. */
    public function logout(Request $request, Response $response): Response
    {
        return Json::write($response, ['ok' => true])
            ->withHeader(
                'Set-Cookie',
                sprintf('%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0', $this->tokens->cookieName()),
            );
    }

    /** Attaches a freshly issued session token to the response. */
    private function withSession(Response $response, User $user, bool $clearState = false): Response
    {
        $token = $this->tokens->issue(
            (int) $user->getId(),
            $user->getUsername(),
            ['role' => $user->getRole()->value],
        );

        $response = $response->withAddedHeader(
            'Set-Cookie',
            sprintf('%s=%s; %s', $this->tokens->cookieName(), $token, $this->tokens->cookieOptions()),
        );

        if ($clearState) {
            $response = $response->withAddedHeader(
                'Set-Cookie',
                sprintf('%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0', self::STATE_COOKIE),
            );
        }

        return $response;
    }
}
