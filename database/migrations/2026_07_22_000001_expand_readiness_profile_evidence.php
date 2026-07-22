<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passport_validation_runs', function (Blueprint $table): void {
            $table->string('score_algorithm', 80)->default('weighted_ratio')->after('rule_set_version');
            $table->char('rule_set_fingerprint', 64)->nullable()->after('score_algorithm_version');
            $table->json('profile_snapshot')->nullable()->after('weights_snapshot');
        });

        Schema::table('passport_validation_results', function (Blueprint $table): void {
            $table->unsignedInteger('configured_weight')->nullable()->after('severity');
            $table->string('source_type', 80)->nullable()->after('field');
            $table->string('source_field', 160)->nullable()->after('source_type');
            $table->json('fix_action_snapshot')->nullable()->after('source_field');
        });

        Schema::table('product_passport_versions', function (Blueprint $table): void {
            $table->string('readiness_profile', 80)->nullable()->after('content_checksum');
            $table->unsignedInteger('readiness_profile_version')->nullable()->after('readiness_profile');
            $table->char('readiness_rule_set_fingerprint', 64)->nullable()->after('readiness_profile_version');
        });

        DB::statement("ALTER TABLE passport_validation_runs ADD CONSTRAINT pvr_rule_set_fingerprint_check CHECK (rule_set_fingerprint IS NULL OR rule_set_fingerprint REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE product_passport_versions ADD CONSTRAINT ppv_readiness_fingerprint_check CHECK (readiness_rule_set_fingerprint IS NULL OR readiness_rule_set_fingerprint REGEXP '^[0-9a-f]{64}$')");

        $this->replacePublishedVersionTrigger();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_update');
        DB::statement('ALTER TABLE product_passport_versions DROP CHECK ppv_readiness_fingerprint_check');
        DB::statement('ALTER TABLE passport_validation_runs DROP CHECK pvr_rule_set_fingerprint_check');

        Schema::table('product_passport_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'readiness_profile',
                'readiness_profile_version',
                'readiness_rule_set_fingerprint',
            ]);
        });

        Schema::table('passport_validation_results', function (Blueprint $table): void {
            $table->dropColumn([
                'configured_weight',
                'source_type',
                'source_field',
                'fix_action_snapshot',
            ]);
        });

        Schema::table('passport_validation_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'score_algorithm',
                'rule_set_fingerprint',
                'profile_snapshot',
            ]);
        });

        $this->replacePublishedVersionTrigger(false);
    }

    private function replacePublishedVersionTrigger(bool $includeReadinessColumns = true): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_update');

        $readinessChecks = $includeReadinessColumns
            ? <<<'SQL'
                            OR NOT (NEW.readiness_profile <=> OLD.readiness_profile)
                            OR NOT (NEW.readiness_profile_version <=> OLD.readiness_profile_version)
                            OR NOT (NEW.readiness_rule_set_fingerprint <=> OLD.readiness_rule_set_fingerprint)
SQL
            : '';

        DB::unprepared(<<<SQL
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
                            {$readinessChecks}
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
