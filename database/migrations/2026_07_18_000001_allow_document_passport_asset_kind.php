<?php

use App\Support\Database\CheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        CheckConstraints::drop('product_passport_assets', 'product_passport_assets_kind_check');
        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_kind_check CHECK (kind IN ('product_media', 'variant_media', 'document'))");
    }

    public function down(): void
    {
        CheckConstraints::drop('product_passport_assets', 'product_passport_assets_kind_check');
        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_kind_check CHECK (kind IN ('product_media', 'variant_media'))");
    }
};
