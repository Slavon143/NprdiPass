<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE product_documents ADD CONSTRAINT product_documents_current_version_fk FOREIGN KEY (company_id, id, current_version_id) REFERENCES product_document_versions(company_id, document_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE product_documents DROP FOREIGN KEY product_documents_current_version_fk');
    }
};
