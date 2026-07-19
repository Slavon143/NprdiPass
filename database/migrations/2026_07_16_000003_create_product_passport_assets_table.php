<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_passport_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('passport_id');
            $table->unsignedBigInteger('version_id');
            $table->string('kind', 30);
            $table->char('source_resource_uuid', 36)->nullable();
            $table->string('role', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('language', 5)->nullable();
            $table->string('mime_type', 100);
            $table->string('file_extension', 20);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum_sha256', 64);
            $table->string('storage_key', 500)->unique();
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');

            $table->index(['company_id', 'passport_id', 'version_id'], 'product_passport_assets_company_passport_version_index');
            $table->index(['version_id', 'sort_order', 'id'], 'product_passport_assets_version_sort_index');
            $table->index(['company_id', 'checksum_sha256'], 'product_passport_assets_company_checksum_index');
        });

        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_kind_check CHECK (kind IN ('product_media', 'variant_media', 'document'))");
        DB::statement('ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_size_check CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_checksum_format_check CHECK (checksum_sha256 REGEXP '^[0-9a-fA-F]{64}$')");
        DB::statement('ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_width_check CHECK (width IS NULL OR width > 0)');
        DB::statement('ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_height_check CHECK (height IS NULL OR height > 0)');
        DB::statement('ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_sort_order_check CHECK (sort_order >= 0)');
        DB::statement("ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_storage_key_no_traversal_check CHECK (storage_key NOT LIKE '%../%' AND storage_key NOT LIKE '%\\%' AND storage_key NOT LIKE '/%')");

        DB::statement('ALTER TABLE product_passport_assets ADD CONSTRAINT product_passport_assets_company_passport_version_foreign FOREIGN KEY (company_id, passport_id, version_id) REFERENCES product_passport_versions(company_id, passport_id, id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passport_assets_prevent_update
            BEFORE UPDATE ON product_passport_assets
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport asset manifest rows are immutable.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passport_assets_prevent_published_delete
            BEFORE DELETE ON product_passport_assets
            FOR EACH ROW
            BEGIN
                DECLARE ver_status VARCHAR(20);

                SELECT status INTO ver_status
                FROM product_passport_versions
                WHERE id = OLD.version_id;

                IF ver_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Parent version not found for passport asset.';
                END IF;

                IF ver_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete asset of a published, superseded, or withdrawn passport version.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_assets_prevent_published_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_assets_prevent_update');

        Schema::dropIfExists('product_passport_assets');
    }
};
