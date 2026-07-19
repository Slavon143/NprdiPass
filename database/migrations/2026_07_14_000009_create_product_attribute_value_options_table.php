<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_value_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('attribute_definition_id');
            $table->unsignedBigInteger('product_attribute_value_id');
            $table->unsignedBigInteger('attribute_option_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['company_id', 'product_attribute_value_id', 'attribute_option_id'],
                'product_attr_value_opts_unique'
            );
            $table->index(
                ['company_id', 'attribute_definition_id'],
                'product_attr_value_opts_company_def_index'
            );
            $table->index(
                ['company_id', 'attribute_option_id'],
                'product_attr_value_opts_company_option_index'
            );

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE product_attribute_value_options ADD CONSTRAINT product_attr_value_opts_value_foreign FOREIGN KEY (company_id, attribute_definition_id, product_attribute_value_id) REFERENCES product_attribute_values(company_id, attribute_definition_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE product_attribute_value_options ADD CONSTRAINT product_attr_value_opts_option_foreign FOREIGN KEY (company_id, attribute_definition_id, attribute_option_id) REFERENCES attribute_options(company_id, attribute_definition_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_value_options');
    }
};
