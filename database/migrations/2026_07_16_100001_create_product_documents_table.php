<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->string('status', 20);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'status'], 'product_documents_company_product_status_index');
            $table->unique(['company_id', 'id'], 'product_documents_company_id_unique');

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE product_documents ADD CONSTRAINT product_documents_status_check CHECK (status IN ('active','archived'))");

        DB::statement('ALTER TABLE product_documents ADD CONSTRAINT product_documents_company_product_fk FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
