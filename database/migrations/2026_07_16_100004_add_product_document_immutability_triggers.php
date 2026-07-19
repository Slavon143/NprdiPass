<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('
            CREATE TRIGGER product_documents_prevent_identity_update
            BEFORE UPDATE ON product_documents
            FOR EACH ROW
            BEGIN
                IF NEW.uuid != OLD.uuid THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'product_documents.uuid is immutable.\';
                END IF;
                IF NEW.company_id != OLD.company_id THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'product_documents.company_id is immutable.\';
                END IF;
                IF NEW.product_id != OLD.product_id THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'product_documents.product_id is immutable.\';
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER product_document_versions_prevent_update
            BEFORE UPDATE ON product_document_versions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'product_document_versions are immutable.\';
            END
        ');

        DB::unprepared('
            CREATE TRIGGER product_document_versions_prevent_delete
            BEFORE DELETE ON product_document_versions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'product_document_versions cannot be deleted.\';
            END
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_documents_prevent_identity_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_delete');
    }
};
