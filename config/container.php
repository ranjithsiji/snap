<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use JuryTool\Infrastructure\Commons\CommonsClient;
use JuryTool\Infrastructure\Doctrine\RandFunction;
use JuryTool\Service\ActivityLogger;
use JuryTool\Service\AssignmentService;
use JuryTool\Service\AuthService;
use JuryTool\Service\ImportService;
use JuryTool\Service\MeetingService;
use JuryTool\Service\RoundDerivationService;
use JuryTool\Service\RoundPopulationService;
use JuryTool\Service\StatisticsService;
use JuryTool\Service\TokenService;
use JuryTool\Service\VotingService;
use JuryTool\Service\WikimediaOAuthService;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Normalises settings into DBAL connection parameters. SQLite takes a path
 * rather than host/port credentials, which the test suite relies on.
 *
 * @param array<string, mixed> $db
 * @return array<string, mixed>
 */
function jurytool_dbal_params(array $db): array
{
    if (str_contains((string) $db['driver'], 'sqlite')) {
        $dir = dirname((string) $db['path']);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return [
            'driver' => $db['driver'],
            'path' => $db['path'],
        ];
    }

    return [
        'driver' => $db['driver'],
        'host' => $db['host'],
        'port' => $db['port'],
        'dbname' => $db['dbname'],
        'user' => $db['user'],
        'password' => $db['password'],
        'charset' => $db['charset'],
    ];
}

return [
    'settings' => static fn (): array => require __DIR__ . '/settings.php',

    ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface
        => new ResponseFactory(),

    LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
        $settings = $c->get('settings')['logger'];
        $logDir = dirname((string) $settings['path']);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0o775, true);
        }

        $logger = new Logger((string) $settings['name']);
        $logger->pushHandler(
            new StreamHandler($settings['path'], Level::fromName((string) $settings['level']))
        );

        return $logger;
    },

    EntityManagerInterface::class => static function (ContainerInterface $c): EntityManagerInterface {
        $settings = $c->get('settings');
        $doctrine = $settings['doctrine'];

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: $doctrine['entity_dirs'],
            isDevMode: (bool) $doctrine['dev_mode'],
            proxyDir: $doctrine['proxy_dir'],
        );

        // PHP 8.4 lazy objects back Doctrine's proxies natively, which
        // avoids generating proxy classes on disk entirely.
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        // Lets the assignment allocator shuffle rows in the database.
        $config->addCustomNumericFunction('RAND', RandFunction::class);

        $connection = DriverManager::getConnection(
            jurytool_dbal_params($settings['db']),
            $config,
        );

        return new EntityManager($connection, $config);
    },

    CommonsClient::class => static fn (ContainerInterface $c) => new CommonsClient(
        $c->get('settings')['commons'],
        $c->get(LoggerInterface::class),
    ),

    TokenService::class => static fn (ContainerInterface $c) => new TokenService(
        $c->get('settings')['auth'],
    ),

    AuthService::class => static fn (ContainerInterface $c) => new AuthService(
        $c->get(EntityManagerInterface::class),
        $c->get('settings')['auth'],
    ),

    WikimediaOAuthService::class => static fn (ContainerInterface $c) => new WikimediaOAuthService(
        $c->get('settings')['oauth'],
        $c->get(LoggerInterface::class),
    ),

    ImportService::class => static fn (ContainerInterface $c) => new ImportService(
        $c->get(CommonsClient::class),
        $c->get(EntityManagerInterface::class),
        $c->get(LoggerInterface::class),
    ),

    ActivityLogger::class => static fn (ContainerInterface $c) => new ActivityLogger(
        $c->get(EntityManagerInterface::class),
    ),

    AssignmentService::class => static fn (ContainerInterface $c) => new AssignmentService(
        $c->get(EntityManagerInterface::class),
        $c->get(LoggerInterface::class),
    ),

    RoundPopulationService::class => static fn (ContainerInterface $c) => new RoundPopulationService(
        $c->get(EntityManagerInterface::class),
        $c->get(AssignmentService::class),
        $c->get(LoggerInterface::class),
    ),

    VotingService::class => static fn (ContainerInterface $c) => new VotingService(
        $c->get(EntityManagerInterface::class),
    ),

    StatisticsService::class => static fn (ContainerInterface $c) => new StatisticsService(
        $c->get(EntityManagerInterface::class),
    ),

    MeetingService::class => static fn (ContainerInterface $c) => new MeetingService(
        $c->get(EntityManagerInterface::class),
        $c->get(StatisticsService::class),
        $c->get(LoggerInterface::class),
    ),

    RoundDerivationService::class => static fn (ContainerInterface $c) => new RoundDerivationService(
        $c->get(EntityManagerInterface::class),
        $c->get(RoundPopulationService::class),
        $c->get(LoggerInterface::class),
    ),
];
