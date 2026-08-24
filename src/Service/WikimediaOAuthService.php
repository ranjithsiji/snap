<?php

declare(strict_types=1);

namespace JuryTool\Service;

use JsonException;
use JuryTool\Support\DomainException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Wikimedia OAuth 2.0 authorization-code flow.
 *
 * Only three HTTP interactions are involved — redirect the user, exchange
 * the code for a token, read the profile — so this talks to the endpoints
 * directly rather than through an OAuth client library.
 */
class WikimediaOAuthService
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Whether an OAuth consumer has been configured for this deployment. */
    public function isConfigured(): bool
    {
        return !empty($this->settings['client_id'])
            && !empty($this->settings['client_secret']);
    }

    /**
     * URL to send the user to, plus the state value that must be stored and
     * checked when they come back.
     *
     * @return array{url: string, state: string}
     */
    public function authorizationRequest(): array
    {
        $this->assertConfigured();

        // Random state, echoed back by the provider, proves the callback
        // belongs to a flow this server started (CSRF protection).
        $state = bin2hex(random_bytes(16));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) $this->settings['client_id'],
            'redirect_uri' => (string) $this->settings['redirect_uri'],
            'state' => $state,
        ]);

        return [
            'url' => $this->settings['authorize_url'] . '?' . $query,
            'state' => $state,
        ];
    }

    /**
     * Exchanges an authorization code for an access token.
     *
     * @return string The access token.
     */
    public function exchangeCode(string $code): string
    {
        $this->assertConfigured();

        $response = $this->post((string) $this->settings['token_url'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) $this->settings['client_id'],
            'client_secret' => (string) $this->settings['client_secret'],
            'redirect_uri' => (string) $this->settings['redirect_uri'],
        ]);

        if (!isset($response['access_token']) || !is_string($response['access_token'])) {
            $this->logger->error('OAuth token exchange returned no access token', [
                'error' => $response['error'] ?? null,
            ]);

            throw new DomainException('Wikimedia did not return an access token.', 502);
        }

        return $response['access_token'];
    }

    /**
     * Reads the identity behind an access token.
     *
     * @return array{username: string, centralAuthId: int|null}
     */
    public function fetchProfile(string $accessToken): array
    {
        $response = $this->get((string) $this->settings['profile_url'], $accessToken);

        $username = $response['username'] ?? null;

        if (!is_string($username) || $username === '') {
            throw new DomainException('Wikimedia profile response contained no username.', 502);
        }

        // Present for accounts with a unified global login, absent otherwise.
        $centralId = $response['sub'] ?? null;

        return [
            'username' => $username,
            'centralAuthId' => is_numeric($centralId) ? (int) $centralId : null,
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new DomainException(
                'Wikimedia login is not configured on this server.',
                503,
            );
        }
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function post(string $url, array $fields): array
    {
        return $this->send($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function get(string $url, string $accessToken): array
    {
        return $this->send($url, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);
    }

    /**
     * @param array<int, mixed> $options
     * @return array<string, mixed>
     */
    private function send(string $url, array $options): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialise HTTP client.');
        }

        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->settings['timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'WikiLovesJuryTool/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false) {
            throw new DomainException("Could not reach Wikimedia: $error", 502);
        }

        try {
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Wikimedia returned an unreadable response.', 502);
        }

        if (!is_array($decoded)) {
            throw new DomainException('Wikimedia returned an unexpected response.', 502);
        }

        if ($status >= 400) {
            $message = $decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? 'unknown error';

            // Wikimedia answers a redirect_uri mismatch with a bare
            // "unknown error", which says nothing about the one setting
            // that actually has to be right. The URI is not a secret —
            // the browser has already seen it in the authorize link — so
            // naming it here turns an opaque 502 into something fixable.
            $this->logger->error('OAuth request rejected', [
                'status' => $status,
                'message' => $message,
                'redirect_uri' => $this->settings['redirect_uri'] ?? null,
            ]);

            throw new DomainException(sprintf(
                'Wikimedia rejected the request: %s. The redirect URI sent was "%s" — it must match '
                . 'the callback URL registered on the OAuth consumer exactly.',
                $message,
                (string) ($this->settings['redirect_uri'] ?? ''),
            ), 502);
        }

        return $decoded;
    }
}
