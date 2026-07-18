<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE product_passport_assets DROP CHECK product_passport_assets_kind_check');
        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_kind_check CHECK (kind IN ('product_media', 'variant_media', 'document'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE product_passport_assets DROP CHECK product_passport_assets_kind_check');
        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_kind_check CHECK (kind IN ('product_media', 'variant_media'))");
    }
};
