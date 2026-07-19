<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_passports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('public_id', 36)->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->string('status', 20);
            $table->string('default_language', 5)->default('sv');
            $table->json('enabled_languages');
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            $table->unique(['company_id', 'id'], 'product_passports_company_id_unique');
            $table->unique(['company_id', 'product_id'], 'product_passports_company_product_unique');
            $table->index(['company_id', 'status'], 'product_passports_company_status_index');
            $table->index(['company_id', 'created_at'], 'product_passports_company_created_index');
        });

        DB::statement("ALTER TABLE product_passports ADD CONSTRAINT product_passports_status_check CHECK (status IN ('draft', 'published', 'unpublished', 'archived'))");
        DB::statement("ALTER TABLE product_passports ADD CONSTRAINT product_passports_default_language_check CHECK (default_language REGEXP '^[a-z]{2}$')");
        DB::statement("ALTER TABLE product_passports ADD CONSTRAINT product_passports_public_id_format_check CHECK (public_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')");
        DB::statement('ALTER TABLE product_passports ADD CONSTRAINT product_passports_company_product_foreign FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passports_prevent_identity_update
            BEFORE UPDATE ON product_passports
            FOR EACH ROW
            BEGIN
                IF NEW.uuid <> OLD.uuid THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_passports.uuid is immutable.';
                END IF;
                IF NEW.public_id <> OLD.public_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_passports.public_id is immutable.';
                END IF;
                IF NEW.company_id <> OLD.company_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_passports.company_id is immutable.';
                END IF;
                IF NEW.product_id <> OLD.product_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_passports.product_id is immutable.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_passports_prevent_identity_update');

        Schema::dropIfExists('product_passports');
    }
};
