<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! in_array(DB::connection()->getDatabaseName(), ['nordipass_testing', 'nordipass_reconcile_20261004_testing', 'nordipass_commercial_20261004_testing'], true)) {
        throw new RuntimeException('Owned isolated storage schema required.');
    }
    $this->artisan('migrate')->assertSuccessful();
});

test('release verification rejects missing and weakened storage uniqueness without repairing it', function () {
    $this->artisan('release:verify-schema')->assertSuccessful();
    $table = 'commercial_storage_objects';
    $index = 'commercial_storage_physical_unique';
    $columns = ['company_uuid', 'physical_key'];
    Schema::table($table, fn ($blueprint) => $blueprint->dropUnique($index));
    try {
        $this->artisan('release:verify-schema')->expectsOutputToContain('uniqueness')->assertFailed();
        Schema::table($table, fn ($blueprint) => $blueprint->index($columns, $index));
        $this->artisan('release:verify-schema')->expectsOutputToContain('uniqueness')->assertFailed();
        expect(DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->where('INDEX_NAME', $index)->value('NON_UNIQUE'))->toBe(1);
    } finally {
        if (Schema::hasIndex($table, $index)) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($index));
        }
        Schema::table($table, fn ($blueprint) => $blueprint->unique($columns, $index));
    }
    $this->artisan('release:verify-schema')->assertSuccessful();
});

test('schema rollback refuses to discard released storage operation history', function () {
    $uuid = (string) Str::uuid();
    DB::table('commercial_storage_balances')->insert(['company_uuid' => $uuid, 'used_bytes' => 0, 'initialized_at' => now()]);
    DB::table('commercial_storage_objects')->insert([
        'company_uuid' => $uuid, 'physical_key' => str_repeat('f', 64), 'disk' => 'catalog_media',
        'storage_key' => $uuid.'/released.bin', 'operation_uuid' => (string) Str::uuid(),
        'state' => 'released', 'reserved_bytes' => 10, 'charged_bytes' => 0,
    ]);
    try {
        $migration = require database_path('migrations/2026_10_04_000003_create_commercial_storage.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain storage accounting');
        expect(Schema::hasTable('commercial_storage_balances'))->toBeTrue()
            ->and(Schema::hasTable('commercial_storage_objects'))->toBeTrue()
            ->and(DB::table('commercial_storage_objects')->where('company_uuid', $uuid)->count())->toBe(1);
    } finally {
        DB::table('commercial_storage_objects')->where('company_uuid', $uuid)->delete();
        DB::table('commercial_storage_balances')->where('company_uuid', $uuid)->delete();
    }
});
