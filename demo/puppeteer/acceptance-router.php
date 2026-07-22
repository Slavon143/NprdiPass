<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__.'/../../public'.$path;

    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.acceptance');

$app->handleRequest(Request::capture());
