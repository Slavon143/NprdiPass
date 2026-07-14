<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('attribute_definition_id');
            $table->string('value_text', 1000)->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 20, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->unsignedBigInteger('value_option_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'variant_attr_values_company_id_unique');
            $table->unique(
                ['company_id', 'attribute_definition_id', 'id'],
                'variant_attr_values_company_def_id_unique'
            );
            $table->unique(['company_id', 'product_variant_id', 'attribute_definition_id'], 'variant_attr_values_entity_def_unique');
            $table->index(['company_id', 'product_variant_id'], 'variant_attr_values_company_variant_index');
            $table->index(['company_id', 'attribute_definition_id'], 'variant_attr_values_company_def_index');
            $table->index(['attribute_definition_id', 'value_option_id'], 'variant_attr_values_def_option_index');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE variant_attribute_values ADD CONSTRAINT variant_attr_values_company_variant_foreign FOREIGN KEY (company_id, product_variant_id) REFERENCES product_variants(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE variant_attribute_values ADD CONSTRAINT variant_attr_values_company_def_foreign FOREIGN KEY (company_id, attribute_definition_id) REFERENCES attribute_definitions(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE variant_attribute_values ADD CONSTRAINT variant_attr_values_company_def_option_foreign FOREIGN KEY (company_id, attribute_definition_id, value_option_id) REFERENCES attribute_options(company_id, attribute_definition_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE variant_attribute_values ADD CONSTRAINT variant_attr_values_one_value_check CHECK ((value_text IS NOT NULL) + (value_integer IS NOT NULL) + (value_decimal IS NOT NULL) + (value_boolean IS NOT NULL) + (value_date IS NOT NULL) + (value_option_id IS NOT NULL) <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_values');
    }
};
