<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Run with writers paused: MySQL DDL and trigger replacement cannot be atomic.
        Schema::table('companies', fn (Blueprint $table) => $table->unsignedTinyInteger('commercial_model_version')->nullable());
        Schema::table('products', function (Blueprint $table): void {
            // Existing products keep their existing paid catalogue membership.
            $table->boolean('managed')->default(true);
            $table->index(['company_id', 'managed', 'deleted_at', 'status', 'id'], 'products_managed_capacity_index');
        });
        Schema::table('catalog_product_capacities', fn (Blueprint $table) => $table->unsignedBigInteger('total_products_count')->nullable());
        $this->dropTriggers();
        $this->installTriggers(true);
        DB::statement('UPDATE catalog_product_capacities c SET total_products_count = (SELECT COUNT(*) FROM products p WHERE p.company_id = c.company_id)');
    }

    public function down(): void
    {
        // Disabling purchases is a code rollback. Removing lifecycle data requires explicit data migration.
        if (DB::table('products')->where('managed', false)->exists()
            || DB::table('companies')->whereNotNull('commercial_model_version')->exists()) {
            throw new RuntimeException('Commercial lifecycle data exists; retain the additive schema or restore the verified database backup.');
        }
        $this->dropTriggers();
        $this->installProductTriggers('catalog_product_capacities', 'products_count', 'company_id');
        DB::unprepared('CREATE TRIGGER companies_catalog_capacity_insert AFTER INSERT ON companies FOR EACH ROW
            INSERT INTO catalog_product_capacities (company_id, products_count) VALUES (NEW.id, NULL)');
        Schema::table('catalog_product_capacities', fn (Blueprint $table) => $table->dropColumn('total_products_count'));
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_managed_capacity_index');
            $table->dropColumn('managed');
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('commercial_model_version'));
    }

    private function dropTriggers(): void
    {
        foreach (['products_capacity_insert', 'products_capacity_delete', 'products_capacity_update', 'companies_catalog_capacity_insert'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
    }

    private function installTriggers(bool $commercial): void
    {
        $managedNew = $commercial ? 'AND NEW.managed = 1' : '';
        $managedOld = $commercial ? 'AND OLD.managed = 1' : '';
        $declarations = 'DECLARE capacity_company BIGINT UNSIGNED DEFAULT NULL;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET capacity_company = NULL;';
        $lock = fn (string $owner): string => "SELECT company_id INTO capacity_company FROM catalog_product_capacities WHERE company_id = {$owner} FOR UPDATE;
            IF capacity_company IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing catalogue capacity row'; END IF;";
        $newLock = $lock('NEW.company_id');
        $oldLock = $lock('OLD.company_id');
        $insertTotal = $commercial ? ', total_products_count = total_products_count + 1' : '';
        $deleteTotal = $commercial ? ', total_products_count = total_products_count - 1' : '';
        $moveTotal = $commercial ? ', total_products_count = total_products_count + IF(company_id = OLD.company_id, -1, 1)' : '';
        // NULL managed counts remain unknown; total rows include retained soft-deleted products.
        DB::unprepared("CREATE TRIGGER products_capacity_insert AFTER INSERT ON products FOR EACH ROW BEGIN
            {$declarations}
            {$newLock}
            UPDATE catalog_product_capacities SET products_count = products_count + IF(NEW.deleted_at IS NULL AND NEW.status IN ('draft','active') {$managedNew}, 1, 0) {$insertTotal}
            WHERE company_id = NEW.company_id;
        END");
        DB::unprepared("CREATE TRIGGER products_capacity_delete AFTER DELETE ON products FOR EACH ROW BEGIN
            {$declarations}
            {$oldLock}
            UPDATE catalog_product_capacities SET products_count = products_count - IF(OLD.deleted_at IS NULL AND OLD.status IN ('draft','active') {$managedOld}, 1, 0) {$deleteTotal}
            WHERE company_id = OLD.company_id;
        END");
        DB::unprepared("CREATE TRIGGER products_capacity_update AFTER UPDATE ON products FOR EACH ROW BEGIN
            DECLARE old_slot INT;
            DECLARE new_slot INT;
            {$declarations}
            SET old_slot = IF(OLD.deleted_at IS NULL AND OLD.status IN ('draft','active') {$managedOld}, 1, 0);
            SET new_slot = IF(NEW.deleted_at IS NULL AND NEW.status IN ('draft','active') {$managedNew}, 1, 0);
            IF OLD.company_id = NEW.company_id THEN
                IF old_slot <> new_slot THEN
                    {$newLock}
                    UPDATE catalog_product_capacities SET products_count = products_count + new_slot - old_slot WHERE company_id = NEW.company_id;
                END IF;
            ELSE
                IF OLD.company_id < NEW.company_id THEN {$oldLock} {$newLock} ELSE {$newLock} {$oldLock} END IF;
                UPDATE catalog_product_capacities SET products_count = products_count + IF(company_id = OLD.company_id, -old_slot, new_slot) {$moveTotal}
                WHERE company_id IN (OLD.company_id, NEW.company_id) ORDER BY company_id;
            END IF;
        END");
        $columns = $commercial ? ', total_products_count' : '';
        $values = $commercial ? ', 0' : '';
        DB::unprepared("CREATE TRIGGER companies_catalog_capacity_insert AFTER INSERT ON companies FOR EACH ROW
            INSERT INTO catalog_product_capacities (company_id, products_count {$columns}) VALUES (NEW.id, NULL {$values})");
    }

    private function installProductTriggers(string $table, string $count, string $key): void
    {
        // A missing row must fail closed; a NULL count remains unknown.
        // Current reads and record locks also apply behind older RR snapshots.
        $declarations = $table === 'companies' ? '' : '
            DECLARE capacity_company BIGINT UNSIGNED DEFAULT NULL;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET capacity_company = NULL;';
        $lock = fn (string $company): string => $table === 'companies' ? '' : "
            SELECT company_id INTO capacity_company FROM catalog_product_capacities WHERE company_id={$company} FOR UPDATE;
            IF capacity_company IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing catalogue capacity row'; END IF;";
        $newLock = $lock('NEW.company_id');
        $oldLock = $lock('OLD.company_id');
        $orderedLocks = $table === 'companies' ? '' : "
            IF OLD.company_id < NEW.company_id THEN
                IF old_slot = 1 THEN {$oldLock} END IF;
                IF new_slot = 1 THEN {$newLock} END IF;
            ELSE
                IF new_slot = 1 THEN {$newLock} END IF;
                IF old_slot = 1 THEN {$oldLock} END IF;
            END IF;";
        DB::unprepared("CREATE TRIGGER products_capacity_insert AFTER INSERT ON products FOR EACH ROW
            BEGIN {$declarations}
                IF NEW.deleted_at IS NULL AND NEW.status IN ('draft', 'active') THEN
                    {$newLock}
                    UPDATE {$table} SET {$count} = {$count} + 1 WHERE {$key} = NEW.company_id;
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER products_capacity_delete AFTER DELETE ON products FOR EACH ROW
            BEGIN {$declarations}
                IF OLD.deleted_at IS NULL AND OLD.status IN ('draft', 'active') THEN
                    {$oldLock}
                    UPDATE {$table} SET {$count} = {$count} - 1 WHERE {$key} = OLD.company_id;
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER products_capacity_update AFTER UPDATE ON products FOR EACH ROW
            BEGIN
                DECLARE old_slot INT;
                DECLARE new_slot INT;
                {$declarations}
                SET old_slot = IF(OLD.deleted_at IS NULL AND OLD.status IN ('draft', 'active'), 1, 0);
                SET new_slot = IF(NEW.deleted_at IS NULL AND NEW.status IN ('draft', 'active'), 1, 0);
                IF OLD.company_id = NEW.company_id THEN
                    IF old_slot <> new_slot THEN
                        {$newLock}
                        UPDATE {$table} SET {$count} = {$count} + new_slot - old_slot WHERE {$key} = NEW.company_id;
                    END IF;
                ELSE
                    {$orderedLocks}
                    UPDATE {$table} SET {$count} = CASE
                        WHEN {$key} = OLD.company_id THEN {$count} - old_slot
                        ELSE {$count} + new_slot END
                    WHERE ({$key} = OLD.company_id AND old_slot = 1) OR ({$key} = NEW.company_id AND new_slot = 1)
                    ORDER BY {$key};
                END IF;
            END");
    }
};
