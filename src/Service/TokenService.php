<?php

declare(strict_types=1);

namespace JuryTool\Service;

use JsonException;

/**
 * Issues and verifies signed session tokens.
 *
 * Deliberately dependency-free: the format is a compact JWS (HS256) built
 * with PHP's own hash_hmac, which avoids pulling in a JWT library for what
 * amounts to sign-and-verify over a small payload.
 */
class TokenService
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    /**
     * Creates a token identifying a user.
     *
     * @param array<string, mixed> $claims Additional claims to embed.
     */
    public function issue(int $userId, string $username, array $claims = []): string
    {
        $now = time();
        $ttl = (int) $this->settings['jwt_ttl'];

        $payload = $claims + [
            'sub' => $userId,
            'username' => $username,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $signingInput = $this->encode($header) . '.' . $this->encode($payload);

        return $signingInput . '.' . $this->sign($signingInput);
    }

    /**
     * Verifies a token and returns its claims, or null when the token is
     * malformed, tampered with, or expired.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header64, $payload64, $signature] = $parts;

        // hash_equals keeps the comparison constant-time, so a wrong
        // signature cannot be recovered byte-by-byte through timing.
        if (!hash_equals($this->sign("$header64.$payload64"), $signature)) {
            return null;
        }

        $header = $this->decode($header64);

        // Reject anything not signed with the algorithm we issue, so a
        // token claiming "alg":"none" is never honoured.
        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $claims = $this->decode($payload64);

        if ($claims === null) {
            return null;
        }

        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    public function cookieName(): string
    {
        return (string) $this->settings['cookie_name'];
    }

    /** Cookie attributes for the session token. */
    public function cookieOptions(): string
    {
        $parts = [
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=' . (int) $this->settings['jwt_ttl'],
        ];

        if ($this->settings['cookie_secure']) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function sign(string $input): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $input, (string) $this->settings['jwt_secret'], true)
        );
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return $this->base64UrlEncode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function decode(string $segment): ?array
    {
        $json = $this->base64UrlDecode($segment);

        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
