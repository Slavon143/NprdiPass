<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('original_filename');
            $table->string('storage_path', 500);
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum_sha256', 64);
            $table->string('alt_text', 500)->nullable();
            $table->string('caption', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'product_id', 'id'], 'media_company_product_id_unique');
            $table->unique(
                ['company_id', 'product_id', 'product_variant_id', 'id'],
                'media_company_product_variant_id_unique'
            );
            $table->index(['company_id', 'product_id'], 'media_company_product_index');
            $table->index(['company_id', 'product_variant_id'], 'media_company_variant_index');
            $table->index(['company_id', 'checksum_sha256'], 'media_company_checksum_index');
            $table->index(['product_id', 'sort_order'], 'media_product_sort_index');
            $table->index(['product_variant_id', 'sort_order'], 'media_variant_sort_index');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE product_media ADD CONSTRAINT media_company_product_foreign FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE product_media ADD CONSTRAINT media_company_product_variant_foreign FOREIGN KEY (company_id, product_id, product_variant_id) REFERENCES product_variants(company_id, product_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE product_media ADD CONSTRAINT media_size_check CHECK (size_bytes >= 0)');
        DB::statement('ALTER TABLE product_media ADD CONSTRAINT media_width_check CHECK (width IS NULL OR width > 0)');
        DB::statement('ALTER TABLE product_media ADD CONSTRAINT media_height_check CHECK (height IS NULL OR height > 0)');
        DB::statement("ALTER TABLE product_media ADD CONSTRAINT media_checksum_format_check CHECK (checksum_sha256 REGEXP '^[0-9a-fA-F]{64}$')");
        DB::statement('ALTER TABLE product_media ADD CONSTRAINT media_sort_order_check CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
