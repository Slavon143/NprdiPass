<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_passport_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('passport_id');
            $table->string('status', 20);
            $table->unsignedInteger('version_number')->nullable();
            $table->unsignedInteger('draft_revision')->default(1);
            $table->string('schema_version', 20)->default('1.0');
            $table->json('payload');
            $table->char('content_checksum', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('published_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            $table->unique(['passport_id', 'version_number'], 'product_passport_versions_passport_version_unique');
            $table->index(['passport_id', 'status'], 'product_passport_versions_passport_status_index');
            $table->index(['company_id', 'status'], 'product_passport_versions_company_status_index');
            $table->unique(['company_id', 'passport_id', 'id'], 'product_passport_versions_company_passport_id_unique');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE product_passport_versions ADD CONSTRAINT product_passport_versions_status_check CHECK (status IN ('draft', 'published', 'superseded', 'withdrawn'))");
        DB::statement('ALTER TABLE product_passport_versions ADD CONSTRAINT product_passport_versions_version_number_check CHECK (version_number IS NULL OR version_number > 0)');
        DB::statement('ALTER TABLE product_passport_versions ADD CONSTRAINT product_passport_versions_draft_revision_check CHECK (draft_revision >= 1)');
        DB::statement("ALTER TABLE product_passport_versions ADD CONSTRAINT product_passport_versions_checksum_format_check CHECK (content_checksum IS NULL OR content_checksum REGEXP '^[0-9a-fA-F]{64}$')");

        DB::statement('ALTER TABLE product_passport_versions ADD COLUMN active_draft_passport_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = \'draft\' THEN passport_id ELSE NULL END) STORED');
        DB::statement('ALTER TABLE product_passport_versions ADD UNIQUE INDEX product_passport_versions_active_draft_unique (active_draft_passport_id)');

        DB::statement('ALTER TABLE product_passport_versions ADD CONSTRAINT product_passport_versions_company_passport_foreign FOREIGN KEY (company_id, passport_id) REFERENCES product_passports(company_id, id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passport_versions_prevent_published_update
            BEFORE UPDATE ON product_passport_versions
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'superseded' OR OLD.status = 'withdrawn' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Superseded and withdrawn passport versions are immutable.';
                END IF;

                IF OLD.status = 'published' THEN
                    IF NEW.status = 'superseded' THEN
                        IF NEW.payload <> OLD.payload OR NEW.content_checksum <> OLD.content_checksum OR NEW.version_number <> OLD.version_number OR NEW.published_at <> OLD.published_at OR NEW.published_by <> OLD.published_by OR NEW.uuid <> OLD.uuid OR NEW.company_id <> OLD.company_id OR NEW.passport_id <> OLD.passport_id OR NEW.draft_revision <> OLD.draft_revision OR NEW.schema_version <> OLD.schema_version OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only status and superseded_at may change when superseding a published version.';
                        END IF;
                    ELSEIF NEW.status = 'withdrawn' THEN
                        IF NEW.payload <> OLD.payload OR NEW.content_checksum <> OLD.content_checksum OR NEW.version_number <> OLD.version_number OR NEW.published_at <> OLD.published_at OR NEW.published_by <> OLD.published_by OR NEW.uuid <> OLD.uuid OR NEW.company_id <> OLD.company_id OR NEW.passport_id <> OLD.passport_id OR NEW.draft_revision <> OLD.draft_revision OR NEW.schema_version <> OLD.schema_version OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only status and withdrawn_at may change when withdrawing a published version.';
                        END IF;
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published versions may only transition to superseded or withdrawn.';
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passport_versions_prevent_published_delete
            BEFORE DELETE ON product_passport_versions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published, superseded, and withdrawn passport versions cannot be deleted.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_update');
        }

        Schema::dropIfExists('product_passport_versions');
    }
};
