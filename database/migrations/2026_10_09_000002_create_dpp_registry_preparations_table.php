<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpp_registry_preparations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('passport_id');
            $table->unsignedBigInteger('published_version_id');
            $table->string('environment', 20);
            $table->string('status', 20)->default('blocked');
            $table->char('snapshot_checksum', 64);
            $table->text('passport_uri')->nullable();
            $table->text('registration_uri')->nullable();
            $table->json('blockers');
            $table->timestamp('created_at');
            $table->unique(['published_version_id', 'environment'], 'dpp_registry_preparation_version_env_unique');
            $table->index(['company_id', 'passport_id'], 'dpp_registry_preparation_company_passport_index');
            $table->foreign(['company_id', 'passport_id', 'published_version_id'], 'dpp_registry_preparation_version_fk')
                ->references(['company_id', 'passport_id', 'id'])->on('product_passport_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE dpp_registry_preparations ADD CONSTRAINT dpp_registry_preparation_local_check CHECK (status = 'blocked' AND registration_uri IS NULL AND environment IN ('acceptance', 'production'))");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER dpp_registry_preparations_no_update
            BEFORE UPDATE ON dpp_registry_preparations FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registry preparations are immutable.'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER dpp_registry_preparations_no_delete
            BEFORE DELETE ON dpp_registry_preparations FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registry preparations are immutable.'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dpp_registry_preparations_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS dpp_registry_preparations_no_update');
        Schema::dropIfExists('dpp_registry_preparations');
    }
};
