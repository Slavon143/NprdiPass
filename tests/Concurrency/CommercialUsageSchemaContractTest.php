<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('release verification rejects missing or weakened usage uniqueness without repairing it', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBeIn(['nordipass_testing', 'nordipass_commercial_20261004_testing']);
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->artisan('release:verify-schema')->assertSuccessful();
    foreach ([
        ['commercial_usage_buckets', 'commercial_usage_window_unique', ['company_id', 'metric', 'window_start']],
        ['commercial_usage_events', 'commercial_usage_operation_unique', ['company_id', 'metric', 'operation_id']],
    ] as [$table, $index, $columns]) {
        // Preserve the company FK's supporting index while deliberately removing
        // the quota invariant from this owned, disposable database.
        Schema::table($table, fn ($blueprint) => $blueprint->index('company_id', 'usage_fk_support'));
        try {
            Schema::table($table, fn ($blueprint) => $blueprint->dropUnique($index));
            $this->artisan('release:verify-schema')->expectsOutputToContain('commercial usage uniqueness')->assertFailed();
            Schema::table($table, fn ($blueprint) => $blueprint->index($columns, $index));
            $this->artisan('release:verify-schema')->expectsOutputToContain('commercial usage uniqueness')->assertFailed();
            expect(DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', $table)->where('INDEX_NAME', $index)->value('NON_UNIQUE'))->toBe(1);
            Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($index));
        } finally {
            Schema::table($table, fn ($blueprint) => $blueprint->unique($columns, $index));
            Schema::table($table, fn ($blueprint) => $blueprint->dropIndex('usage_fk_support'));
        }
    }
    $this->artisan('release:verify-schema')->assertSuccessful();
});
