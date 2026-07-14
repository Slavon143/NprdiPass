<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('primary_media_id')->nullable();
            $table->string('name')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('sku_normalized', 100)->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('mpn', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'id'], 'variants_company_id_unique');
            $table->unique(['company_id', 'product_id', 'id'], 'variants_company_product_id_unique');
            $table->unique(['company_id', 'sku_normalized'], 'variants_company_sku_unique');
            $table->unique(['company_id', 'gtin'], 'variants_company_gtin_unique');
            $table->index(['company_id', 'product_id'], 'variants_company_product_index');
            $table->index(['company_id', 'status'], 'variants_company_status_index');
            $table->index(['product_id', 'sort_order'], 'variants_product_sort_index');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_company_product_foreign FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT variants_gtin_format_check CHECK (gtin IS NULL OR (gtin REGEXP '^[0-9]+$' AND CHAR_LENGTH(gtin) IN (8, 12, 13, 14)))");
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT variants_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_sort_order_check CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
