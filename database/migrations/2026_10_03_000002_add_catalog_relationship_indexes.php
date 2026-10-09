<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parent IDs are globally unique. Eloquent eager loads these relations
        // by parent ID; company-first indexes do not support that access path.
        // Existing tenant constraints and company indexes remain in place.
        Schema::table('product_attribute_values', function (Blueprint $table): void {
            $table->index('product_id', 'product_attr_values_product_index');
        });
        Schema::table('variant_attribute_values', function (Blueprint $table): void {
            $table->index('product_variant_id', 'variant_attr_values_variant_index');
        });
        Schema::table('category_product', function (Blueprint $table): void {
            $table->index(['product_id', 'category_id'], 'category_product_product_category_index');
        });
        Schema::table('product_attribute_value_options', function (Blueprint $table): void {
            $table->index(['product_attribute_value_id', 'attribute_option_id'], 'product_attr_value_opts_value_option_index');
        });
        Schema::table('variant_attribute_value_options', function (Blueprint $table): void {
            $table->index(['variant_attribute_value_id', 'attribute_option_id'], 'variant_attr_value_opts_value_option_index');
        });
    }

    public function down(): void
    {
        Schema::table('variant_attribute_value_options', function (Blueprint $table): void {
            $table->dropIndex('variant_attr_value_opts_value_option_index');
        });
        Schema::table('product_attribute_value_options', function (Blueprint $table): void {
            $table->dropIndex('product_attr_value_opts_value_option_index');
        });
        Schema::table('category_product', function (Blueprint $table): void {
            $table->dropIndex('category_product_product_category_index');
        });
        Schema::table('variant_attribute_values', function (Blueprint $table): void {
            $table->dropIndex('variant_attr_values_variant_index');
        });
        Schema::table('product_attribute_values', function (Blueprint $table): void {
            $table->dropIndex('product_attr_values_product_index');
        });
    }
};
