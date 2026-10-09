<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Called only by the site hooks, never exposed as a web endpoint.
$base = '/home/codecraf/nordipass-staging';
$root = realpath($base.'/current');
if (! $root || ! str_starts_with($root, $base.'/releases/')) {
    exit(2);
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('staging') || realpath($root.'/.env') !== $base.'/shared/.env') {
    exit(2);
}
$database = DB::connection()->getDatabaseName();
if ($database !== 'codecraf_nordipass_staging') {
    exit(2);
}
$connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$heartbeatState = require __DIR__.'/heartbeat-state.php';
$heartbeat = static function (string $key, int $maxAge) use ($heartbeatState): array {
    // Cache::get can delete expired database cache rows. Admission/drain
    // inspection must remain read-only, even during a long backup pause.
    $row = DB::table(config('cache.stores.database.table', 'cache'))
        ->where('key', config('cache.prefix').$key)->first(['value', 'expiration']);

    return $heartbeatState($row, $maxAge, time());
};
$cliWriters = 0;
foreach (glob('/proc/[0-9]*') as $process) {
    if ((int) basename($process) === getmypid() || @fileowner($process) !== posix_geteuid()
        || ! str_starts_with((string) @readlink($process.'/exe'), '/opt/alt/php84/usr/bin/php')) {
        continue;
    }
    $arguments = @file_get_contents($process.'/cmdline');
    $cwd = @readlink($process.'/cwd');
    if ((is_string($arguments) && str_contains($arguments, $base.'/'))
        || (is_string($cwd) && str_starts_with($cwd, $base.'/'))) {
        $cliWriters++;
    }
}
$report = ['checked_at' => gmdate('c'), 'environment' => 'staging', 'release' => basename($root),
    'scoped_cli_writers' => $cliWriters,
    'database' => $database, 'other_database_connections' => DB::table('information_schema.PROCESSLIST')->where('DB', $database)->where('ID', '!=', $connectionId)->count(),
    'database_events' => DB::table('information_schema.EVENTS')->where('EVENT_SCHEMA', $database)->count(),
    'reserved_jobs' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
    'pending_jobs' => DB::table('jobs')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'scheduler_heartbeat' => $heartbeat(config('health.scheduler_heartbeat_key'), (int) config('health.scheduler_max_age')),
    'worker_heartbeat' => $heartbeat(config('health.worker_heartbeat_key'), (int) config('health.worker_max_age')),
];
echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
