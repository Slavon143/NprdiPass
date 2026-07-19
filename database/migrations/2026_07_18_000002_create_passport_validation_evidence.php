<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_validation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('passport_id');
            $table->unsignedBigInteger('draft_version_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('profile', 80);
            $table->unsignedInteger('profile_version');
            $table->string('schema_version', 20);
            $table->unsignedInteger('rule_set_version');
            $table->unsignedInteger('score_algorithm_version');
            $table->json('weights_snapshot');
            $table->unsignedInteger('earned_points');
            $table->unsignedInteger('applicable_points');
            $table->unsignedTinyInteger('score');
            $table->string('status', 30);
            $table->unsignedInteger('draft_revision');
            $table->char('source_checksum', 64);
            $table->unsignedInteger('passed_count');
            $table->unsignedInteger('blocker_count');
            $table->unsignedInteger('warning_count');
            $table->unsignedInteger('recommendation_count');
            $table->unsignedInteger('not_applicable_count');
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['company_id', 'passport_id'], 'pvr_company_passport_foreign')
                ->references(['company_id', 'id'])->on('product_passports')->restrictOnDelete();
            $table->foreign(['company_id', 'passport_id', 'draft_version_id'], 'pvr_draft_version_foreign')
                ->references(['company_id', 'passport_id', 'id'])->on('product_passport_versions')->restrictOnDelete();
            $table->unique(['company_id', 'passport_id', 'id'], 'pvr_company_passport_id_unique');
            $table->unique(['company_id', 'id'], 'pvr_company_id_unique');
            $table->index(['passport_id', 'validated_at'], 'pvr_passport_validated_index');
        });

        Schema::create('passport_validation_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('validation_run_id');
            $table->string('code', 160);
            $table->string('rule_group', 40);
            $table->string('severity', 30);
            $table->string('status', 30);
            $table->string('title_key', 200);
            $table->string('message_key', 200);
            $table->string('section', 80)->nullable();
            $table->string('field', 120)->nullable();
            $table->json('safe_context');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'validation_run_id'], 'pvr_result_run_foreign')
                ->references(['company_id', 'id'])->on('passport_validation_runs')->restrictOnDelete();
            $table->unique(['validation_run_id', 'code'], 'pvr_result_run_code_unique');
            $table->index(['company_id', 'code'], 'pvr_result_company_code_index');
        });

        Schema::table('product_passport_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('validation_run_id')->nullable()->after('content_checksum');
            $table->json('readiness_evidence')->nullable()->after('validation_run_id');
            $table->foreign(['company_id', 'passport_id', 'validation_run_id'], 'ppv_validation_run_foreign')
                ->references(['company_id', 'passport_id', 'id'])->on('passport_validation_runs')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE passport_validation_runs ADD CONSTRAINT pvr_score_check CHECK (score BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE passport_validation_runs ADD CONSTRAINT pvr_points_check CHECK (earned_points <= applicable_points)');
        DB::statement("ALTER TABLE passport_validation_runs ADD CONSTRAINT pvr_checksum_check CHECK (source_checksum REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE passport_validation_runs ADD CONSTRAINT pvr_status_check CHECK (status IN ('not_ready', 'ready_with_warnings', 'ready'))");
        DB::statement("ALTER TABLE passport_validation_results ADD CONSTRAINT pvr_result_status_check CHECK (status IN ('passed', 'failed', 'not_applicable'))");
        DB::statement("ALTER TABLE passport_validation_results ADD CONSTRAINT pvr_result_severity_check CHECK (severity IN ('blocker', 'warning', 'recommendation'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER passport_validation_runs_prevent_update
            BEFORE UPDATE ON passport_validation_runs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation runs are immutable.'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER passport_validation_runs_prevent_delete
            BEFORE DELETE ON passport_validation_runs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation runs are immutable.'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER passport_validation_results_prevent_update
            BEFORE UPDATE ON passport_validation_results FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation results are immutable.'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER passport_validation_results_prevent_delete
            BEFORE DELETE ON passport_validation_results FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation results are immutable.'
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_update');
        $this->createVersionImmutabilityTrigger();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_update');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_results_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_results_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_runs_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_runs_prevent_update');

        Schema::table('product_passport_versions', function (Blueprint $table): void {
            $table->dropForeign('ppv_validation_run_foreign');
            $table->dropColumn(['validation_run_id', 'readiness_evidence']);
        });

        Schema::dropIfExists('passport_validation_results');
        Schema::dropIfExists('passport_validation_runs');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passport_versions_prevent_published_update
            BEFORE UPDATE ON product_passport_versions FOR EACH ROW
            BEGIN
                IF OLD.status IN ('superseded', 'withdrawn') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Superseded and withdrawn passport versions are immutable.';
                END IF;
                IF OLD.status = 'published' AND NEW.status NOT IN ('superseded', 'withdrawn') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published versions may only transition to superseded or withdrawn.';
                END IF;
            END
        SQL);
    }

    private function createVersionImmutabilityTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_passport_versions_prevent_published_update
            BEFORE UPDATE ON product_passport_versions
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'superseded' OR OLD.status = 'withdrawn' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Superseded and withdrawn passport versions are immutable.';
                END IF;

                IF OLD.status = 'published' THEN
                    IF NEW.status = 'superseded' OR NEW.status = 'withdrawn' THEN
                        IF NOT (NEW.payload <=> OLD.payload)
                            OR NOT (NEW.content_checksum <=> OLD.content_checksum)
                            OR NOT (NEW.validation_run_id <=> OLD.validation_run_id)
                            OR NOT (NEW.readiness_evidence <=> OLD.readiness_evidence)
                            OR NOT (NEW.version_number <=> OLD.version_number)
                            OR NOT (NEW.published_at <=> OLD.published_at)
                            OR NOT (NEW.published_by <=> OLD.published_by)
                            OR NOT (NEW.uuid <=> OLD.uuid)
                            OR NOT (NEW.company_id <=> OLD.company_id)
                            OR NOT (NEW.passport_id <=> OLD.passport_id)
                            OR NOT (NEW.draft_revision <=> OLD.draft_revision)
                            OR NOT (NEW.schema_version <=> OLD.schema_version)
                            OR NOT (NEW.created_by <=> OLD.created_by)
                            OR NOT (NEW.created_at <=> OLD.created_at) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published passport evidence is immutable.';
                        END IF;
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published versions may only transition to superseded or withdrawn.';
                    END IF;
                END IF;
            END
        SQL);
    }
};
