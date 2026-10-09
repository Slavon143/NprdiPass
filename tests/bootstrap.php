<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Default tests use their own storage and bootstrap caches. Environment
// credentials may establish MySQL access but never select a live provider.
$root = dirname(__DIR__).'/storage/framework/testing-suite';
foreach (['', '/framework/views', '/framework/cache/data', '/framework/sessions', '/logs'] as $suffix) {
    if (! is_dir($root.$suffix) && ! mkdir($root.$suffix, 0775, true) && ! is_dir($root.$suffix)) {
        throw new RuntimeException('Unable to initialize isolated test storage.');
    }
}
$paths = [
    'LARAVEL_STORAGE_PATH' => $root,
    'APP_CONFIG_CACHE' => 'storage/framework/testing-suite/config.php',
    'APP_ROUTES_CACHE' => 'storage/framework/testing-suite/routes.php',
    'APP_EVENTS_CACHE' => 'storage/framework/testing-suite/events.php',
    'APP_PACKAGES_CACHE' => 'storage/framework/testing-suite/packages.php',
    'APP_SERVICES_CACHE' => 'storage/framework/testing-suite/services.php',
    'CATALOG_MEDIA_ROOT' => $root.'/media',
    'DOCUMENTS_ROOT' => $root.'/documents',
    'IMPORTS_ROOT' => $root.'/imports',
    'PASSPORT_ASSETS_ROOT' => $root.'/assets',
];
foreach ($paths as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}
