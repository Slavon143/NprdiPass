<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('attribute_definition_id');
            $table->string('label');
            $table->string('code');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'attribute_definition_id', 'id'], 'attr_options_company_definition_id_unique');
            $table->unique(['company_id', 'attribute_definition_id', 'code'], 'attr_options_company_definition_code_unique');
            $table->index(['company_id', 'attribute_definition_id'], 'attr_options_company_definition_index');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE attribute_options ADD CONSTRAINT attr_options_company_definition_foreign FOREIGN KEY (company_id, attribute_definition_id) REFERENCES attribute_definitions(company_id, id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE attribute_options ADD CONSTRAINT attr_options_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE attribute_options ADD CONSTRAINT attr_options_sort_order_check CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_options');
    }
};
