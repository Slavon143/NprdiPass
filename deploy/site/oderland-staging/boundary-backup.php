<?php

use App\Services\Infrastructure\PersistentFileArchive;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$base = '/home/codecraf/nordipass-staging';
$pauseId = $argv[1] ?? '';
if (preg_match('/^[0-9]{10,24}$/D', $pauseId) !== 1) {
    exit(2);
}
$root = realpath($base.'/current');
$directory = $base.'/shared/storage/framework/nordipass-writer-gate';
$targetDatabase = 'codecraf_np_boundary_20261009';
$pause = json_decode(file_get_contents($base.'/shared/writers-paused'), true, flags: JSON_THROW_ON_ERROR);
$drain = json_decode(file_get_contents($directory.'/drain.json'), true, flags: JSON_THROW_ON_ERROR);
if (! $root || ! str_starts_with($root, $base.'/releases/') || ($pause['pause_id'] ?? '') !== $pauseId
    || ($drain['pause_id'] ?? '') !== $pauseId || ($drain['http_and_cron_locks_exclusive'] ?? false) !== true) {
    exit(2);
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('staging') || DB::connection()->getDatabaseName() !== 'codecraf_nordipass_staging') {
    exit(2);
}
// This is an explicitly controlled maintenance operation inside the drained
// boundary. Keep its cache locks/logs out of the database being snapshotted.
config(['cache.default' => 'array', 'logging.default' => 'null', 'queue.default' => 'sync',
    'mail.default' => 'array', 'fortnox.provider' => 'fake', 'billing.provider' => 'fake']);
umask(0077);
$workspace = $base.'/shared/acceptance/boundary-backup-20261009-'.$pauseId;
if (file_exists($workspace) || is_link($workspace)) {
    throw new RuntimeException('Backup boundary already exists; inspect it before retry');
}
mkdir($workspace, 0700);
$proof = ['kind' => 'actual_post_drain_staging_backup_and_restore', 'pause_id' => $pauseId,
    'started_at' => gmdate('c'), 'source_release' => basename($root), 'target_database' => $targetDatabase, 'completed' => false];
$check = static function (bool $condition, string $name): void {
    if (! $condition) {
        throw new RuntimeException('Boundary acceptance failed: '.$name);
    }
};
$content = static function ($connection): array {
    $schema = $connection->getDatabaseName();
    $tables = $connection->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schema)
        ->where('TABLE_TYPE', 'BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME');
    $result = [];
    foreach ($tables as $table) {
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Unexpected table identifier');
        }
        $keys = $connection->table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)->where('INDEX_NAME', 'PRIMARY')->orderBy('SEQ_IN_INDEX')->pluck('COLUMN_NAME');
        if ($keys->isEmpty()) {
            throw new RuntimeException('Unordered table cannot be compared');
        }
        $query = $connection->table($table);
        foreach ($keys as $key) {
            $query->orderBy($key);
        }
        $digest = hash_init('sha256');
        $count = 0;
        foreach ($query->cursor() as $row) {
            hash_update($digest, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $count++;
        }
        $result[$table] = ['rows' => $count, 'sha256' => hash_final($digest)];
    }

    return $result;
};
$triggers = static function ($connection): array {
    $rows = $connection->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
        ->orderBy('TRIGGER_NAME')->get(['TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_ORDER',
            'ACTION_CONDITION', 'ACTION_STATEMENT', 'ACTION_ORIENTATION', 'ACTION_TIMING', 'SQL_MODE', 'DEFINER']);
    $owner = $connection->selectOne('SELECT CURRENT_USER() AS identity')->identity;
    foreach ($rows as $row) {
        if ($row->DEFINER !== $owner) {
            throw new RuntimeException('Unexpected trigger owner; no privilege escalation attempted');
        }
        unset($row->DEFINER);
        $row->ACTION_STATEMENT = str_replace("\r\n", "\n", $row->ACTION_STATEMENT);
    }

    return ['count' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows->all(), JSON_THROW_ON_ERROR))];
};
try {
    $source = DB::connection();
    $schema = $source->getDatabaseName();
    $check($source->table('information_schema.EVENTS')->where('EVENT_SCHEMA', $schema)->count() === 0, 'no_database_events');
    $before = $content($source);
    $beforeTriggers = $triggers($source);
    config(['database.connections.boundary_restore' => [...config('database.connections.mysql'), 'database' => $targetDatabase, 'url' => null]]);
    $target = DB::connection('boundary_restore');
    $check($target->getDatabaseName() === $targetDatabase && $targetDatabase !== $schema, 'isolated_target_identity');
    $check($target->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $targetDatabase)->count() === 1, 'explicit_precreated_target');
    $check($target->table('information_schema.EVENTS')->where('EVENT_SCHEMA', $targetDatabase)->count() === 0, 'no_target_events');
    $targetTables = $target->table('information_schema.TABLES')->where('TABLE_SCHEMA', $targetDatabase)->pluck('TABLE_NAME')->all();
    if ($targetTables !== []) {
        // Only the previously verified, dedicated disposable boundary fixture
        // may be reused. Its full content must still match retained restore
        // proof, and both previous backup artifacts must still verify.
        $owned = json_decode(file_get_contents($directory.'/boundary-target-owner.json'), true, flags: JSON_THROW_ON_ERROR);
        $check(($owned['target_database'] ?? '') === $targetDatabase && ($owned['completed'] ?? false) === true, 'owned_boundary_fixture');
        $check($content($target) === $owned['restored_tables'] && $triggers($target) === $owned['restored_triggers'], 'fixture_has_no_unreviewed_changes');
        foreach ($owned['retained_artifacts'] as $artifact) {
            $path = realpath($artifact['path']);
            $check($path !== false && str_starts_with($path, $base.'/shared/acceptance/boundary-backup-20261009-')
                && hash_file('sha256', $path) === $artifact['sha256'], 'prior_fixture_backup_retained');
        }
        $check(count($targetTables) === count($owned['restored_tables']), 'no_unexpected_views');
        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_keys($owned['restored_tables']) as $table) {
                $check(preg_match('/^[a-zA-Z0-9_]+$/D', $table) === 1, 'safe_fixture_identifier');
                $target->statement('DROP TABLE `'.$table.'`');
            }
        } finally {
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    $check($target->table('information_schema.TABLES')->where('TABLE_SCHEMA', $targetDatabase)->count() === 0, 'target_empty_for_native_restore');
    config(['filesystems.disks.boundary_archive' => ['driver' => 'local', 'root' => $workspace.'/backups', 'throw' => true],
        'backup.disk' => 'boundary_archive', 'backup.path' => 'sets', 'backup.temporary_path' => $workspace.'/temporary',
        'backup.database.enabled' => true, 'backup.files.enabled' => true]);
    $exit = Artisan::call('nordipass:backup');
    file_put_contents($workspace.'/native-backup-private.log', Artisan::output());
    $check($exit === 0, 'native_complete_backup');
    $disk = Storage::disk('boundary_archive');
    $sets = $disk->directories('sets');
    $check(count($sets) === 1, 'one_new_backup_set');
    $backupId = basename($sets[0]);
    $manifest = json_decode($disk->get($sets[0].'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $check($manifest['status'] === 'complete' && $manifest['scope'] === ['database' => true, 'files' => true], 'complete_backup_scope');
    $proof['backup_id'] = $backupId;
    $proof['retained_artifacts'] = [];
    foreach ($manifest['artifacts'] as $artifact) {
        $path = $disk->path($sets[0].'/'.$artifact['path']);
        $check(hash_file('sha256', $path) === $artifact['sha256'] && filesize($path) === $artifact['size'], 'backup_artifact_integrity');
        $proof['retained_artifacts'][] = ['path' => $path, 'sha256' => $artifact['sha256'], 'bytes' => $artifact['size']];
    }
    $check($content($source) === $before && $triggers($source) === $beforeTriggers, 'source_unchanged_during_backup');
    foreach (['production_imports', 'product_documents', 'catalog_media', 'passport_assets'] as $name) {
        config(['filesystems.disks.'.$name.'.root' => $workspace.'/restored/'.$name]);
        Storage::forgetDisk($name);
    }
    $exit = Artisan::call('nordipass:restore', ['backup-id' => $backupId, '--target-connection' => 'boundary_restore']);
    file_put_contents($workspace.'/native-restore-private.log', Artisan::output());
    $check($exit === 0, 'native_complete_isolated_restore');
    $restored = $content($target);
    $restoredTriggers = $triggers($target);
    $check($restored === $before && $restoredTriggers === $beforeTriggers, 'every_row_and_trigger_restored');
    $stores = $app->make(PersistentFileArchive::class)->create($workspace.'/verified-files.tar.gz');
    foreach ($manifest['artifacts']['files']['stores'] as $store) {
        $actual = collect($stores)->firstWhere('disk', $store['disk']);
        $check($actual !== null && $actual['file_count'] === $store['file_count'] && $actual['total_bytes'] === $store['total_bytes']
            && $actual['content_sha256'] === $store['content_sha256'], 'persistent_store_restored_'.$store['disk']);
    }
    $check($content($source) === $before && $triggers($source) === $beforeTriggers, 'main_database_unchanged');
    DB::setDefaultConnection('boundary_restore');
    $check(Artisan::call('release:verify-schema') === 0, 'restored_native_schema');
    $check(Artisan::call('catalog:capacity-reconcile', ['--all-companies' => true]) === 0, 'restored_capacity_without_repair');
    $check(realpath($base.'/current') === $root, 'source_release_unchanged');
    $proof['restored_tables'] = $restored;
    $proof['restored_triggers'] = $restoredTriggers;
    $proof['restored_stores'] = $stores;
    $proof['completed'] = true;
    $proof['completed_at'] = gmdate('c');
    file_put_contents($directory.'/boundary-target-owner.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    $proof['error_class'] = get_class($error);
    // Provider/SQL exceptions can contain private values: keep raw diagnostics
    // inside the private command log, export only a safe acceptance label.
    $proof['error'] = $error instanceof RuntimeException && ! $error instanceof QueryException
        ? $error->getMessage() : 'Boundary backup/isolated restore failed';
}
file_put_contents($workspace.'/result.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($directory.'/backup-proof.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['completed' => $proof['completed'], 'pause_id' => $pauseId, 'backup_id' => $proof['backup_id'] ?? null]).PHP_EOL;
exit($proof['completed'] ? 0 : 1);
