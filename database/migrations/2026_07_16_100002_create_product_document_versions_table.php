<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('version_number');
            $table->string('document_type', 50);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('language', 10);
            $table->string('visibility', 20);
            $table->string('issuer_name')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('original_filename');
            $table->string('mime_type', 50);
            $table->string('file_extension', 20);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('storage_key', 500);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->unique(['document_id', 'version_number'], 'product_document_versions_document_version_unique');
            $table->unique(['storage_key'], 'product_document_versions_storage_key_unique');
            $table->index(['company_id', 'document_id'], 'product_document_versions_company_document_index');
            $table->index(['company_id', 'document_id', 'id'], 'product_document_versions_company_document_id_index');

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_company_document_fk FOREIGN KEY (company_id, document_id) REFERENCES product_documents(company_id, id) ON DELETE RESTRICT');

        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_type_check CHECK (document_type IN ('instruction','declaration_of_conformity','certificate','safety_data_sheet','warranty','technical_data_sheet','recycling_guide','other'))");

        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_visibility_check CHECK (visibility IN ('internal','passport_public'))");

        DB::statement('ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_mime_check CHECK (mime_type = \'application/pdf\')');

        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_extension_check CHECK (file_extension = 'pdf')");

        DB::statement('ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_size_check CHECK (size_bytes > 0)');

        DB::statement('ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_checksum_check CHECK (LENGTH(checksum_sha256) = 64)');

        DB::statement('ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_dates_check CHECK (expires_at IS NULL OR issue_date IS NULL OR expires_at >= issue_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_document_versions');
    }
};
