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
        /**
         * Whether the file asked for SSL to be turned off.
         *
         * Toolforge writes `disable-ssl = true` into replica.my.cnf, and
         * it has to be honoured: the driver would otherwise negotiate TLS
         * against a server not offering it and fail to connect at all.
         */
        public readonly bool $disableSsl = false,
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
                // Rebuilt only to record where it was found; every other
                // field, disable-ssl included, carries over as parsed.
                return new self(
                    $parsed->user,
                    $parsed->password,
                    $path,
                    $parsed->disableSsl,
                );
            }
        }

        return new self();
    }

    /** The tool's directory on Toolforge, where its replica.my.cnf lives. */
    public const TOOL_DIR = '/data/project/snap';

    /**
     * The places a replica.my.cnf may live, most authoritative first.
     *
     * The account's own home directory comes first, resolved through
     * posix_getpwuid() as Wikitech's documented snippet does: it reads the
     * passwd entry of the user the process actually runs as, so it holds
     * where $HOME is unset or points somewhere else — which is exactly the
     * case for a web service. $HOME is kept as a fallback for environments
     * without ext-posix, and the tool directory is named outright so
     * discovery works even if both are wrong.
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

        $tool = $env('TOOL_NAME');

        $dirs = array_filter([
            self::passwdHome(),
            $env('HOME'),
            self::TOOL_DIR,
            $tool !== null ? '/data/project/' . $tool : null,
        ], static fn (?string $dir): bool => $dir !== null && $dir !== '');

        return array_values(array_unique(array_map(
            static fn (string $dir): string => rtrim($dir, '/') . '/replica.my.cnf',
            $dirs,
        )));
    }

    /**
     * The home directory of the user this process runs as, from the passwd
     * database rather than the environment.
     */
    private static function passwdHome(): ?string
    {
        if (!function_exists('posix_getuid') || !function_exists('posix_getpwuid')) {
            return null;
        }

        $entry = posix_getpwuid(posix_getuid());

        return is_array($entry) && !empty($entry['dir']) ? (string) $entry['dir'] : null;
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

            if (!$inClient) {
                continue;
            }

            // A my.cnf flag may appear bare, with no value, in which case
            // its presence alone means "on" — `disable-ssl` is written
            // both ways in the wild.
            if (!str_contains($line, '=')) {
                $values[strtolower(self::cleanValue($line))] = '1';
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[strtolower(trim($key))] = self::cleanValue($value);
        }

        return new self(
            $values['user'] ?? null,
            $values['password'] ?? null,
            'file',
            self::isTruthy($values['disable-ssl'] ?? null),
        );
    }

    /**
     * How my.cnf spells an enabled flag. A bare key is already normalised
     * to "1" by the parser; the rest are the spellings MySQL accepts.
     */
    private static function isTruthy(?string $value): bool
    {
        return $value !== null
            && in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
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
