<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // NULL means unknown, including existing companies. Backfill is separate.
            $table->unsignedBigInteger('catalog_products_count')->nullable();
        });

        // Do not filter out NULL counters: these writes must still acquire the
        // Company mutex, then apply their delta if a waiting backfill initializes it.
        DB::unprepared("CREATE TRIGGER products_capacity_insert AFTER INSERT ON products FOR EACH ROW
            BEGIN
                IF NEW.deleted_at IS NULL AND NEW.status IN ('draft', 'active') THEN
                    UPDATE companies SET catalog_products_count = catalog_products_count + 1 WHERE id = NEW.company_id;
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER products_capacity_delete AFTER DELETE ON products FOR EACH ROW
            BEGIN
                IF OLD.deleted_at IS NULL AND OLD.status IN ('draft', 'active') THEN
                    UPDATE companies SET catalog_products_count = catalog_products_count - 1 WHERE id = OLD.company_id;
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER products_capacity_update AFTER UPDATE ON products FOR EACH ROW
            BEGIN
                DECLARE old_slot INT;
                DECLARE new_slot INT;
                SET old_slot = IF(OLD.deleted_at IS NULL AND OLD.status IN ('draft', 'active'), 1, 0);
                SET new_slot = IF(NEW.deleted_at IS NULL AND NEW.status IN ('draft', 'active'), 1, 0);
                IF OLD.company_id = NEW.company_id THEN
                    IF old_slot <> new_slot THEN
                        UPDATE companies SET catalog_products_count = catalog_products_count + new_slot - old_slot WHERE id = NEW.company_id;
                    END IF;
                ELSE
                    UPDATE companies SET catalog_products_count = CASE
                        WHEN id = OLD.company_id THEN catalog_products_count - old_slot
                        ELSE catalog_products_count + new_slot END
                    WHERE (id = OLD.company_id AND old_slot = 1) OR (id = NEW.company_id AND new_slot = 1)
                    ORDER BY id;
                END IF;
            END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS products_capacity_update');
        DB::unprepared('DROP TRIGGER IF EXISTS products_capacity_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS products_capacity_insert');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('catalog_products_count'));
    }
};
