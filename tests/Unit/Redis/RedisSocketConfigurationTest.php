<?php

use Illuminate\Redis\RedisManager;

/** @param array<string, string|null> $overrides @return array<string, mixed> */
function redisSocketTestConfiguration(array $overrides): array
{
    $values = $overrides + [
        'REDIS_CLIENT' => 'predis', 'REDIS_URL' => null, 'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => '6379', 'REDIS_SCHEME' => null, 'REDIS_PATH' => null,
        'REDIS_USERNAME' => null, 'REDIS_PASSWORD' => null,
        'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1', 'REDIS_PREFIX' => 'socket-test-',
    ];
    $previous = [];
    foreach ($values as $key => $value) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        if ($value === null) {
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
    try {
        return (require config_path('database.php'))['redis'];
    } finally {
        foreach ($previous as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            putenv($process === false ? $key : $key.'='.$process);
        }
    }
}

test('Laravel resolves both Oderland Redis connections through the actual Predis socket client without networking', function () {
    $config = redisSocketTestConfiguration(['REDIS_SCHEME' => 'unix', 'REDIS_PATH' => '/tmp/redis', 'REDIS_PORT' => '0']);
    $manager = new RedisManager(app(), $config['client'], $config);
    foreach (['default' => '0', 'cache' => '1'] as $name => $database) {
        $client = $manager->connection($name)->client();
        $connection = $client->getConnection();
        $parameters = $connection->getParameters();
        expect($parameters->scheme)->toBe('unix')->and($parameters->path)->toBe('/tmp/redis')
            ->and((string) $parameters->database)->toBe($database)->and($connection->isConnected())->toBeFalse();
        $command = $client->createCommand('GET', ['probe']);
        expect($command->getArguments())->toBe(['socket-test-probe']);
    }
});

test('optional socket support preserves implicit TCP and TLS host configuration', function (string $host, string $scheme, string $expectedHost) {
    $config = redisSocketTestConfiguration(['REDIS_HOST' => $host]);
    $manager = new RedisManager(app(), $config['client'], $config);
    $connection = $manager->connection()->client()->getConnection();
    expect($connection->getParameters()->scheme)->toBe($scheme)
        ->and($connection->getParameters()->host)->toBe($expectedHost)
        ->and($connection->isConnected())->toBeFalse();
})->with([
    ['127.0.0.1', 'tcp', '127.0.0.1'],
    ['tls://redis.example.com', 'tls', 'redis.example.com'],
]);
