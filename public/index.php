<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Serves both the JSON API and the built Vue SPA — the frontend is
 * compiled into this directory by Vite, so a single PHP host serves the
 * whole application.
 */

/** @var \Slim\App $app */
$app = require dirname(__DIR__) . '/config/bootstrap.php';

$app->run();
