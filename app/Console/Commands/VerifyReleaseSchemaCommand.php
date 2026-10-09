<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifyReleaseSchemaCommand extends Command
{
    protected $signature = 'release:verify-schema';

    protected $description = 'Read-only check that the database matches this executable release';

    public function handle(): int
    {
        try {
            if (DB::connection()->getDriverName() !== 'mysql'
                || ! Schema::hasTable('catalog_product_capacities')
                || ! Schema::hasColumns('catalog_product_capacities', ['company_id', 'products_count', 'total_products_count'])
                || ! Schema::hasColumn('companies', 'commercial_model_version')
                || ! Schema::hasColumn('products', 'managed')
                || Schema::hasColumn('companies', 'catalog_products_count')) {
                $this->error('Release schema mismatch: catalogue capacity storage.');

                return self::FAILURE;
            }
            foreach ([
                'commercial_usage_buckets' => ['id', 'company_id', 'metric', 'window_start', 'window_end', 'used'],
                'commercial_usage_events' => ['id', 'company_id', 'bucket_id', 'metric', 'operation_id', 'units', 'created_at'],
                'commercial_storage_balances' => ['company_uuid', 'used_bytes', 'initialized_at'],
                'commercial_storage_objects' => ['id', 'company_uuid', 'physical_key', 'disk', 'storage_key', 'operation_uuid', 'state', 'reserved_bytes', 'charged_bytes'],
            ] as $table => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                    $this->error('Release schema mismatch: commercial usage storage.');

                    return self::FAILURE;
                }
            }
            foreach ([
                'commercial_usage_buckets' => ['commercial_usage_window_unique', ['company_id', 'metric', 'window_start']],
                'commercial_usage_events' => ['commercial_usage_operation_unique', ['company_id', 'metric', 'operation_id']],
                'commercial_storage_balances' => ['PRIMARY', ['company_uuid']],
                'commercial_storage_objects' => ['commercial_storage_physical_unique', ['company_uuid', 'physical_key']],
            ] as $table => [$name, $columns]) {
                $index = DB::table('information_schema.STATISTICS')
                    ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', $table)
                    ->where('INDEX_NAME', $name)->orderBy('SEQ_IN_INDEX')->get();
                if ($index->pluck('COLUMN_NAME')->all() !== $columns
                    || $index->contains(fn ($part): bool => (int) $part->NON_UNIQUE !== 0 || $part->SUB_PART !== null)) {
                    $this->error('Release schema mismatch: commercial usage uniqueness.');

                    return self::FAILURE;
                }
            }
            $pathIndex = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', 'product_media')
                ->where('INDEX_NAME', 'media_storage_path_index')->orderBy('SEQ_IN_INDEX')->get();
            if ($pathIndex->pluck('COLUMN_NAME')->all() !== ['storage_path']
                || $pathIndex->contains(fn ($part): bool => $part->SUB_PART !== null)) {
                $this->error('Release schema mismatch: media path lookup index.');

                return self::FAILURE;
            }
            $required = array_keys(app('migrator')->getMigrationFiles(database_path('migrations')));
            $applied = app('migration.repository')->getRan();
            sort($required);
            sort($applied);
            if ($required !== $applied) {
                $this->error('Release schema mismatch: migration set. Coordinated migration or rollback is required.');

                return self::FAILURE;
            }
            $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('TRIGGER_NAME', ['products_capacity_insert', 'products_capacity_update', 'products_capacity_delete', 'companies_catalog_capacity_insert'])
                ->get()->keyBy('TRIGGER_NAME');
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $event) {
                $trigger = $triggers->get('products_capacity_'.$suffix);
                if ($trigger === null || $trigger->EVENT_OBJECT_TABLE !== 'products' || $trigger->EVENT_MANIPULATION !== $event
                    || $trigger->ACTION_TIMING !== 'AFTER' || ! str_contains($trigger->ACTION_STATEMENT, 'catalog_product_capacities')
                    || ! str_contains($trigger->ACTION_STATEMENT, 'products_count') || ! str_contains($trigger->ACTION_STATEMENT, '45000')) {
                    $this->error('Release schema mismatch: product capacity trigger.');

                    return self::FAILURE;
                }
            }
            $companyTrigger = $triggers->get('companies_catalog_capacity_insert');
            if ($companyTrigger === null || $companyTrigger->EVENT_OBJECT_TABLE !== 'companies' || $companyTrigger->EVENT_MANIPULATION !== 'INSERT'
                || $companyTrigger->ACTION_TIMING !== 'AFTER' || ! str_contains($companyTrigger->ACTION_STATEMENT, 'catalog_product_capacities')) {
                $this->error('Release schema mismatch: company capacity trigger.');

                return self::FAILURE;
            }
            foreach (['insert', 'delete', 'update'] as $suffix) {
                $statement = $triggers->get('products_capacity_'.$suffix)->ACTION_STATEMENT;
                if (! str_contains($statement, 'managed') || ! str_contains($statement, 'total_products_count')) {
                    $this->error('Release schema mismatch: managed catalogue capacity trigger.');

                    return self::FAILURE;
                }
            }
            if (! str_contains($companyTrigger->ACTION_STATEMENT, 'total_products_count')) {
                $this->error('Release schema mismatch: managed company capacity trigger.');

                return self::FAILURE;
            }
        } catch (\Throwable) {
            // Connection/DDL errors can contain credentials or operational data.
            $this->error('Unable to verify release schema. Writers must remain stopped.');

            return self::FAILURE;
        }
        $this->info('Release schema matches managed catalogue capacity, commercial usage, storage accounting and the complete migration set.');

        return self::SUCCESS;
    }
}
