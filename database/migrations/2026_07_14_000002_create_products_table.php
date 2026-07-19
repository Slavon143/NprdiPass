<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('primary_category_id')->nullable();
            $table->unsignedBigInteger('default_variant_id')->nullable();
            $table->unsignedBigInteger('primary_media_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('slug_normalized');
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'id'], 'products_company_id_unique');
            $table->unique(['company_id', 'slug_normalized'], 'products_company_slug_unique');
            $table->index(['company_id', 'status'], 'products_company_status_index');
            $table->index(['company_id', 'name'], 'products_company_name_index');
            $table->index(['company_id', 'updated_at'], 'products_company_updated_index');

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

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
