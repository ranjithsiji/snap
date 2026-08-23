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

return [
    'app' => [
        'name' => $env('APP_NAME', 'Wiki Loves Jury Tool'),
        'env' => $env('APP_ENV', 'dev'),
        'debug' => $bool($env('APP_DEBUG'), $env('APP_ENV', 'dev') !== 'prod'),
        'url' => rtrim($env('APP_URL', 'http://localhost:8080') ?? '', '/'),
        'root' => $root,
    ],

    'db' => [
        'driver' => $env('DB_DRIVER', 'pdo_mysql'),
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => (int) ($env('DB_PORT', '3306') ?? 3306),
        'dbname' => $env('DB_NAME', 'jurytool'),
        'user' => $env('DB_USER', 'root'),
        'password' => $env('DB_PASSWORD', ''),
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
            'WikiLovesJuryTool/1.0 (https://github.com/wikiloves/jurytool)'
        ),
        'thumb_width' => (int) ($env('COMMONS_THUMB_WIDTH', '1024') ?? 1024),
        // Commons caps generator queries at 500 for API users.
        'batch_size' => (int) ($env('COMMONS_BATCH_SIZE', '200') ?? 200),
        'timeout' => (int) ($env('COMMONS_TIMEOUT', '30') ?? 30),
    ],

    'logger' => [
        'name' => 'jurytool',
        'path' => $env('LOG_PATH', $root . '/var/log/app.log'),
        'level' => $env('LOG_LEVEL', 'debug'),
    ],
];
