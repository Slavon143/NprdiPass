<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['company_id', 'id', 'default_variant_id'],
                'products_company_id_default_variant_index'
            );
            $table->index(
                ['company_id', 'id', 'primary_media_id'],
                'products_company_id_primary_media_index'
            );
            $table->index(
                ['company_id', 'primary_category_id'],
                'products_company_primary_category_index'
            );
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->index(
                ['company_id', 'product_id', 'id', 'primary_media_id'],
                'variants_company_product_id_primary_media_index'
            );
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_primary_category_foreign FOREIGN KEY (company_id, primary_category_id) REFERENCES categories(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_default_variant_foreign FOREIGN KEY (company_id, id, default_variant_id) REFERENCES product_variants(company_id, product_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_primary_media_foreign FOREIGN KEY (company_id, id, primary_media_id) REFERENCES product_media(company_id, product_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_primary_media_foreign FOREIGN KEY (company_id, product_id, id, primary_media_id) REFERENCES product_media(company_id, product_id, product_variant_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropForeign('variants_primary_media_foreign');
            $table->dropIndex('variants_company_product_id_primary_media_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_primary_media_foreign');
            $table->dropForeign('products_default_variant_foreign');
            $table->dropForeign('products_primary_category_foreign');
            $table->dropIndex('products_company_id_primary_media_index');
            $table->dropIndex('products_company_id_default_variant_index');
            $table->dropIndex('products_company_primary_category_index');
        });
    }
};
