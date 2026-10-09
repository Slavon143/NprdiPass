<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Requires paused writers: MySQL trigger/column DDL is not transactional.
        Schema::create('catalog_product_capacities', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->primary();
            $table->unsignedBigInteger('products_count')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
        DB::statement('INSERT INTO catalog_product_capacities (company_id, products_count) SELECT id, catalog_products_count FROM companies');
        $this->dropProductTriggers();
        $this->installProductTriggers('catalog_product_capacities', 'products_count', 'company_id');
        DB::unprepared('CREATE TRIGGER companies_catalog_capacity_insert AFTER INSERT ON companies FOR EACH ROW
            INSERT INTO catalog_product_capacities (company_id, products_count) VALUES (NEW.id, NULL)');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('catalog_products_count'));
    }

    public function down(): void
    {
        $this->dropProductTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS companies_catalog_capacity_insert');
        Schema::table('companies', fn (Blueprint $table) => $table->unsignedBigInteger('catalog_products_count')->nullable());
        DB::statement('UPDATE companies c JOIN catalog_product_capacities p ON p.company_id=c.id SET c.catalog_products_count=p.products_count');
        $this->installProductTriggers('companies', 'catalog_products_count', 'id');
        Schema::drop('catalog_product_capacities');
    }

    private function dropProductTriggers(): void
    {
        foreach (['update', 'delete', 'insert'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS products_capacity_'.$event);
        }
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
