<?php

declare(strict_types=1);

namespace JuryTool\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Small helpers for reading and writing JSON in actions.
 */
final class Json
{
    /** Writes data as a JSON response body. */
    public static function write(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * Decodes a JSON request body, tolerating form-encoded bodies too so
     * the API works from a plain HTML form as well as from fetch().
     *
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            return $parsed;
        }

        $raw = (string) $request->getBody();

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Reads a required string field.
     *
     * @param array<string, mixed> $data
     */
    public static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw DomainException::badRequest("Field '$key' is required.");
        }

        return trim($value);
    }

    /**
     * Reads an optional string field, treating blank as absent.
     *
     * @param array<string, mixed> $data
     */
    public static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Reads an integer field.
     *
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Reads a boolean field, accepting the several shapes an HTML form and
     * a JSON client each produce.
     *
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Reads an ISO-8601 date field.
     *
     * @param array<string, mixed> $data
     */
    public static function date(array $data, string $key): ?\DateTimeImmutable
    {
        $value = self::optionalString($data, $key);

        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw DomainException::badRequest("Field '$key' is not a valid date.");
        }
    }
}
