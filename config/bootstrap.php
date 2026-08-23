<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use JuryTool\Action\Auth\AuthActions;
use JuryTool\Middleware\AuthenticationMiddleware;
use JuryTool\Middleware\JsonErrorMiddleware;
use JuryTool\Service\AuthService;
use JuryTool\Service\TokenService;
use JuryTool\Service\WikimediaOAuthService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

// .env is optional: settings.php falls back to defaults so the app boots
// without one, which keeps first-run and CI simple.
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/container.php');

if ((getenv('APP_ENV') ?: 'dev') === 'prod') {
    $builder->enableCompilation($root . '/var/cache/di');
}

/** @var ContainerInterface $container */
$container = $builder->build();

// AuthActions needs the app URL to redirect back into the SPA, which is a
// setting rather than a service, so it is wired here.
$container->set(AuthActions::class, static fn (ContainerInterface $c): AuthActions => new AuthActions(
    $c->get(AuthService::class),
    $c->get(TokenService::class),
    $c->get(WikimediaOAuthService::class),
    $c->get('settings')['app']['url'],
));

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$app->add(new AuthenticationMiddleware(
    $container->get(TokenService::class),
    $container->get(AuthService::class),
));

// Outermost, so it catches everything thrown further in.
$app->add(new JsonErrorMiddleware(
    $container->get(ResponseFactoryInterface::class),
    $container->get(LoggerInterface::class),
    (bool) $container->get('settings')['app']['debug'],
));

(require __DIR__ . '/routes.php')($app);

return $app;
