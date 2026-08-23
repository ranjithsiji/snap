<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Commons;

/**
 * Finds the credentials for the Wikimedia replica database.
 *
 * Toolforge writes a `replica.my.cnf` into the tool's home directory when
 * the tool account is created. Locating that file is the tool's way of
 * knowing it is running on Toolforge at all: nothing else distinguishes
 * the environment reliably, and the credentials are never in the repo.
 */
final class ReplicaCredentials
{
    public function __construct(
        public readonly ?string $user = null,
        public readonly ?string $password = null,
        /** Where the credentials came from, for the diagnostics endpoint. */
        public readonly ?string $source = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->user !== null && $this->user !== ''
            && $this->password !== null && $this->password !== '';
    }

    /**
     * Resolves credentials from the environment, then from disk.
     *
     * Explicit environment variables win, so a developer can point at their
     * own replica without touching files. Otherwise the standard Toolforge
     * locations are searched in order of authority.
     *
     * @param callable(string, ?string=): ?string $env
     */
    public static function discover(callable $env): self
    {
        $user = $env('REPLICA_USER') ?? $env('TOOL_REPLICA_USER');
        $password = $env('REPLICA_PASSWORD') ?? $env('TOOL_REPLICA_PASSWORD');

        if ($user !== null && $password !== null) {
            return new self($user, $password, 'environment');
        }

        foreach (self::candidatePaths($env) as $path) {
            if ($path === '' || !is_readable($path)) {
                continue;
            }

            $parsed = self::parseCnf((string) file_get_contents($path));

            if ($parsed->isComplete()) {
                return new self($parsed->user, $parsed->password, $path);
            }
        }

        return new self();
    }

    /**
     * The places a replica.my.cnf may live, most authoritative first.
     *
     * `$HOME` is where Toolforge puts it and is correct in a normal shell,
     * but a web service can run with a different or empty HOME — so the
     * canonical /data/project path is checked as well, derived from the
     * tool name the way Toolforge itself names the directory.
     *
     * @param callable(string, ?string=): ?string $env
     * @return list<string>
     */
    private static function candidatePaths(callable $env): array
    {
        $explicit = $env('REPLICA_CNF');

        if ($explicit !== null) {
            return [$explicit];
        }

        $home = $env('HOME');
        $tool = $env('TOOL_NAME') ?? ($home !== null ? basename($home) : null);

        return array_values(array_filter([
            $home !== null ? $home . '/replica.my.cnf' : null,
            $tool !== null ? '/data/project/' . $tool . '/replica.my.cnf' : null,
        ]));
    }

    /**
     * Reads the [client] section of a my.cnf.
     *
     * Hand-parsed rather than handed to parse_ini_file, which keeps inline
     * `# comments` as part of the value — the documented example file for
     * these credentials carries one, and the resulting username would fail
     * authentication in a way that reads as a wrong password.
     */
    public static function parseCnf(string $contents): self
    {
        $inClient = false;
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (preg_match('/^\[(.+)]$/', $line, $m) === 1) {
                $inClient = strtolower(trim($m[1])) === 'client';
                continue;
            }

            if (!$inClient || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[strtolower(trim($key))] = self::cleanValue($value);
        }

        return new self($values['user'] ?? null, $values['password'] ?? null, 'file');
    }

    /**
     * Strips an inline comment and surrounding quotes from a my.cnf value.
     *
     * A `#` inside quotes is part of the password and must survive; one
     * after whitespace outside quotes starts a comment.
     */
    private static function cleanValue(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $end = strpos($value, $quote, 1);

            if ($end !== false) {
                return substr($value, 1, $end - 1);
            }
        }

        // Unquoted: a comment marker preceded by whitespace ends the value.
        $value = preg_replace('/\s+[#;].*$/', '', $value) ?? $value;

        return trim($value);
    }
}
