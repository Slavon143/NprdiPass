<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('code');
            $table->string('description', 500)->nullable();
            $table->string('type');
            $table->string('scope');
            $table->string('unit', 50)->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('filterable')->default(false);
            $table->boolean('searchable')->default(false);
            $table->json('validation_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'attr_defs_company_id_unique');
            $table->unique(['company_id', 'code'], 'attr_defs_company_code_unique');
            $table->index(['company_id', 'type'], 'attr_defs_company_type_index');
            $table->index(['company_id', 'scope'], 'attr_defs_company_scope_index');

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

        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_type_check CHECK (type IN ('text', 'integer', 'decimal', 'boolean', 'date', 'select', 'multiselect'))");
        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_scope_check CHECK (scope IN ('product', 'variant', 'both'))");
        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE attribute_definitions ADD CONSTRAINT attr_defs_sort_order_check CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
