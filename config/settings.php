<?php

declare(strict_types=1);

/**
 * Application settings, resolved from the environment with sane defaults so
 * the tool boots for local development without a .env file.
 */

$root = dirname(__DIR__);

$env = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
};

$bool = static fn (?string $v, bool $default = false): bool => $v === null
    ? $default
    : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);

/**
 * Toolforge issues one credential pair per tool, in replica.my.cnf, and it
 * opens both the tool's own database on the tools cluster and the Wikimedia
 * replicas. Reading it here means a Toolforge deployment needs no database
 * credentials in .env at all — and no copy of a password in a second file.
 */
$replica = \JuryTool\Infrastructure\Commons\ReplicaCredentials::discover($env);

return [
    'app' => [
        'name' => $env('APP_NAME', 'Snap'),
        'env' => $env('APP_ENV', 'dev'),
        'debug' => $bool($env('APP_DEBUG'), $env('APP_ENV', 'dev') !== 'prod'),
        'url' => rtrim($env('APP_URL', 'http://localhost:8080') ?? '', '/'),
        'root' => $root,
    ],

    /**
     * The tool's own database: campaigns, rounds, votes, users.
     *
     * On Toolforge this lives on the tools cluster and is reached with the
     * credentials from replica.my.cnf, so DB_USER and DB_PASSWORD need not
     * be set there. The database name follows Toolforge's convention of
     * "<credential user>__<suffix>", which is derivable too — so a working
     * deployment needs only DB_HOST, and that only because the same file
     * is used off Toolforge.
     */
    'db' => [
        'driver' => $env('DB_DRIVER', 'pdo_mysql'),
        'host' => $env('DB_HOST', $replica->isComplete() ? 'tools.db.svc.wikimedia.cloud' : '127.0.0.1'),
        'port' => (int) ($env('DB_PORT', '3306') ?? 3306),
        'dbname' => $env(
            'DB_NAME',
            $replica->user !== null ? $replica->user . '__snap' : 'jurytool'
        ),
        'user' => $env('DB_USER', $replica->user ?? 'root'),
        'password' => $env('DB_PASSWORD', $replica->password ?? ''),
        'charset' => 'utf8mb4',
        // Used when DB_DRIVER is pdo_sqlite; handy for tests.
        'path' => $env('DB_PATH', $root . '/var/data.sqlite'),
    ],

    'doctrine' => [
        'dev_mode' => $bool($env('APP_DEBUG'), $env('APP_ENV', 'dev') !== 'prod'),
        'entity_dirs' => [$root . '/src/Domain/Entity'],
        'proxy_dir' => $root . '/var/cache/doctrine/proxies',
        'cache_dir' => $root . '/var/cache/doctrine',
        'migrations_dir' => $root . '/migrations',
        'migrations_namespace' => 'JuryTool\\Migrations',
    ],

    'auth' => [
        // Signing key for session JWTs. MUST be overridden in production.
        'jwt_secret' => $env('JWT_SECRET', 'insecure-dev-secret-change-me'),
        'jwt_ttl' => (int) ($env('JWT_TTL', '43200') ?? 43200), // seconds
        'cookie_name' => $env('AUTH_COOKIE', 'jurytool_session'),
        'cookie_secure' => $bool($env('AUTH_COOKIE_SECURE'), $env('APP_ENV', 'dev') === 'prod'),
        // Local username/password login. Keep on so the tool is usable
        // before an OAuth consumer is registered.
        'local_login_enabled' => $bool($env('LOCAL_LOGIN_ENABLED'), true),
    ],

    // Wikimedia OAuth 2.0 (authorization code flow). Register an owner-only
    // or public consumer at:
    // https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration/propose/oauth2
    'oauth' => [
        'client_id' => $env('OAUTH_CLIENT_ID'),
        'client_secret' => $env('OAUTH_CLIENT_SECRET'),
        'authorize_url' => $env('OAUTH_AUTHORIZE_URL', 'https://meta.wikimedia.org/w/rest.php/oauth2/authorize'),
        'token_url' => $env('OAUTH_TOKEN_URL', 'https://meta.wikimedia.org/w/rest.php/oauth2/access_token'),
        'profile_url' => $env('OAUTH_PROFILE_URL', 'https://meta.wikimedia.org/w/rest.php/oauth2/resource/profile'),
        'redirect_uri' => $env('OAUTH_REDIRECT_URI', ($env('APP_URL', 'http://localhost:8080') ?? '') . '/api/auth/callback'),
        'timeout' => (int) ($env('OAUTH_TIMEOUT', '15') ?? 15),
    ],

    'commons' => [
        'api_url' => $env('COMMONS_API_URL', 'https://commons.wikimedia.org/w/api.php'),
        // The Wikimedia API policy requires a descriptive, contactable agent.
        'user_agent' => $env(
            'COMMONS_USER_AGENT',
            'Snap/1.0 (https://snap.toolforge.org/)'
        ),
        'thumb_width' => (int) ($env('COMMONS_THUMB_WIDTH', '1024') ?? 1024),
        // Commons caps generator queries at 500 for API users.
        'batch_size' => (int) ($env('COMMONS_BATCH_SIZE', '200') ?? 200),
        'timeout' => (int) ($env('COMMONS_TIMEOUT', '30') ?? 30),
    ],

    /**
     * Wikimedia replica database (Toolforge only).
     *
     * When reachable, a category is read with one SQL query instead of
     * paging through the web API — seconds rather than minutes. The tool
     * falls back to the API automatically when this is not configured, so
     * it runs unchanged off Toolforge.
     *
     * Credentials come from Toolforge's replica.my.cnf, or from
     * REPLICA_USER / REPLICA_PASSWORD when pointing at a replica by hand.
     * Finding usable credentials is what switches the fast path on, so no
     * separate flag has to be remembered at deploy time; REPLICA_ENABLED=0
     * forces the API path back on for comparison.
     */
    'replica' => (static function () use ($env, $bool, $replica): array {
        $credentials = $replica;

        return [
            'enabled' => $bool($env('REPLICA_ENABLED'), true) && $credentials->isComplete(),
            'host' => $env('REPLICA_HOST', 'commonswiki.analytics.db.svc.wikimedia.cloud'),
            'port' => (int) ($env('REPLICA_PORT', '3306') ?? 3306),
            'dbname' => $env('REPLICA_DB', 'commonswiki_p'),
            'user' => $credentials->user,
            'password' => $credentials->password,
            'credentials_source' => $credentials->source,
            'thumb_width' => (int) ($env('COMMONS_THUMB_WIDTH', '1024') ?? 1024),
            // Rows per keyset page. The replica is fast, so this is far
            // larger than the API's 500 ceiling.
            'batch_size' => (int) ($env('REPLICA_BATCH_SIZE', '5000') ?? 5000),
        ];
    })(),

    'logger' => [
        'name' => 'jurytool',
        'path' => $env('LOG_PATH', $root . '/var/log/app.log'),
        'level' => $env('LOG_LEVEL', 'debug'),
    ],
];
