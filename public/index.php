<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Serves both the JSON API and the built Vue SPA — the frontend is
 * compiled into this directory by Vite, so a single PHP host serves the
 * whole application.
 */

// Checked before bootstrap, which require_once's the autoloader and so
// dies with a bare 500 whose cause is only in error.log. A deployment
// that has not had `composer install` run is the likeliest way to reach
// this, and it should say so.
if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');

    echo "Snap is not fully installed: PHP dependencies are missing.\n\n";
    echo "Run in the project directory:\n";
    echo "  composer install --no-dev --optimize-autoloader\n";

    exit;
}

/** @var \Slim\App $app */
$app = require dirname(__DIR__) . '/config/bootstrap.php';

$app->run();
