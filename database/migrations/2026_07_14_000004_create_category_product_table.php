<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'product_id', 'category_id'], 'category_product_unique');
            $table->index(['company_id', 'product_id'], 'category_product_company_product_index');
            $table->index(['company_id', 'category_id'], 'category_product_company_category_index');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE category_product ADD CONSTRAINT category_product_company_product_foreign FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE category_product ADD CONSTRAINT category_product_company_category_foreign FOREIGN KEY (company_id, category_id) REFERENCES categories(company_id, id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
