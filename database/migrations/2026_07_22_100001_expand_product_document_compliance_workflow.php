<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_delete');

        DB::statement('ALTER TABLE product_document_versions DROP CHECK product_document_versions_type_check');

        Schema::table('product_document_versions', function (Blueprint $table): void {
            $table->string('safe_display_filename')->nullable()->after('original_filename');
            $table->json('metadata')->nullable()->after('visibility');
            $table->string('review_status', 30)->default('approved')->after('metadata');
            $table->string('approval_status', 30)->default('approved')->after('review_status');
            $table->timestamp('submitted_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('submitted_by_user_id')->nullable()->after('submitted_at');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_by_user_id');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->after('reviewed_at');
            $table->timestamp('approved_at')->nullable()->after('reviewed_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at');
            $table->text('review_comment')->nullable()->after('approved_by_user_id');
            $table->text('rejection_reason')->nullable()->after('review_comment');
            $table->string('certificate_number', 120)->nullable()->after('issuer_name');
            $table->string('issuing_body', 255)->nullable()->after('certificate_number');
            $table->string('declaration_identifier', 120)->nullable()->after('issuing_body');
            $table->string('evidence_type', 120)->nullable()->after('declaration_identifier');
            $table->string('topic_code', 120)->nullable()->after('evidence_type');
            $table->string('standard_reference', 255)->nullable()->after('topic_code');
            $table->string('applicable_market', 120)->nullable()->after('standard_reference');
            $table->string('reference_url', 1000)->nullable()->after('applicable_market');
            $table->date('valid_from')->nullable()->after('issue_date');
            $table->date('valid_until')->nullable()->after('valid_from');
            $table->boolean('file_available')->default(true)->after('storage_key');
            $table->timestamp('published_at')->nullable()->after('file_available');
            $table->unsignedInteger('published_snapshot_count')->default(0)->after('published_at');

            $table->index(['company_id', 'review_status', 'approval_status'], 'product_document_versions_review_index');
            $table->index(['company_id', 'document_type', 'approval_status'], 'product_document_versions_type_approval_index');
            $table->index(['company_id', 'valid_until'], 'product_document_versions_valid_until_index');
            $table->unique(['company_id', 'id'], 'product_document_versions_company_id_unique');
            $table->foreign('submitted_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        DB::table('product_document_versions')->update([
            'safe_display_filename' => DB::raw('original_filename'),
            'valid_from' => DB::raw('issue_date'),
            'valid_until' => DB::raw('expires_at'),
            'approved_at' => DB::raw('created_at'),
            'approved_by_user_id' => DB::raw('created_by_user_id'),
        ]);

        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_type_check CHECK (document_type IN ('general_document','manual','technical_specification','safety_data','instruction','declaration_of_conformity','certificate','test_report','safety_data_sheet','warranty','warranty_document','technical_data_sheet','recycling_document','recycling_guide','repair_document','environmental_evidence','compliance_evidence','other'))");
        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_review_status_check CHECK (review_status IN ('draft','pending_review','approved','rejected','cancelled'))");
        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_approval_status_check CHECK (approval_status IN ('pending','approved','rejected'))");
        DB::statement('ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_valid_dates_check CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)');

        Schema::create('product_document_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('decision', 30);
            $table->string('previous_review_status', 30)->nullable();
            $table->string('new_review_status', 30);
            $table->string('previous_approval_status', 30)->nullable();
            $table->string('new_approval_status', 30);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['company_id', 'document_id', 'version_id'], 'product_document_review_decisions_scope_index');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('actor_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign(['company_id', 'document_id'], 'product_document_review_document_fk')->references(['company_id', 'id'])->on('product_documents')->onDelete('restrict');
            $table->foreign(['company_id', 'version_id'], 'product_document_review_version_fk')->references(['company_id', 'id'])->on('product_document_versions')->onDelete('restrict');
        });

        Schema::create('product_document_variant', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->boolean('public_inclusion')->default(false);
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_id', 'product_variant_id'], 'product_document_variant_unique');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign(['company_id', 'document_id'], 'product_document_variant_document_fk')->references(['company_id', 'id'])->on('product_documents')->onDelete('restrict');
            $table->foreign(['company_id', 'product_variant_id'], 'product_document_variant_variant_fk')->references(['company_id', 'id'])->on('product_variants')->onDelete('restrict');
        });

        $this->createMutableReviewTriggers();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_delete');

        Schema::dropIfExists('product_document_variant');
        Schema::dropIfExists('product_document_review_decisions');

        DB::statement('ALTER TABLE product_document_versions DROP CHECK product_document_versions_type_check');
        DB::statement('ALTER TABLE product_document_versions DROP CHECK product_document_versions_review_status_check');
        DB::statement('ALTER TABLE product_document_versions DROP CHECK product_document_versions_approval_status_check');
        DB::statement('ALTER TABLE product_document_versions DROP CHECK product_document_versions_valid_dates_check');

        Schema::table('product_document_versions', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by_user_id']);
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropForeign(['approved_by_user_id']);
            $table->dropIndex('product_document_versions_review_index');
            $table->dropIndex('product_document_versions_type_approval_index');
            $table->dropIndex('product_document_versions_valid_until_index');
            $table->dropUnique('product_document_versions_company_id_unique');
            $table->dropColumn([
                'safe_display_filename',
                'metadata',
                'review_status',
                'approval_status',
                'submitted_at',
                'submitted_by_user_id',
                'reviewed_at',
                'reviewed_by_user_id',
                'approved_at',
                'approved_by_user_id',
                'review_comment',
                'rejection_reason',
                'certificate_number',
                'issuing_body',
                'declaration_identifier',
                'evidence_type',
                'topic_code',
                'standard_reference',
                'applicable_market',
                'reference_url',
                'valid_from',
                'valid_until',
                'file_available',
                'published_at',
                'published_snapshot_count',
            ]);
        });

        DB::statement("ALTER TABLE product_document_versions ADD CONSTRAINT product_document_versions_type_check CHECK (document_type IN ('instruction','declaration_of_conformity','certificate','safety_data_sheet','warranty','technical_data_sheet','recycling_guide','other'))");
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

    private function createMutableReviewTriggers(): void
    {
        DB::unprepared('
            CREATE TRIGGER product_document_versions_prevent_update
            BEFORE UPDATE ON product_document_versions
            FOR EACH ROW
            BEGIN
                IF NEW.uuid != OLD.uuid
                    OR NEW.company_id != OLD.company_id
                    OR NEW.document_id != OLD.document_id
                    OR NEW.version_number != OLD.version_number
                    OR NEW.document_type != OLD.document_type
                    OR NEW.title != OLD.title
                    OR NOT (NEW.description <=> OLD.description)
                    OR NEW.language != OLD.language
                    OR NEW.visibility != OLD.visibility
                    OR NOT (NEW.metadata <=> OLD.metadata)
                    OR NOT (NEW.issuer_name <=> OLD.issuer_name)
                    OR NOT (NEW.certificate_number <=> OLD.certificate_number)
                    OR NOT (NEW.issuing_body <=> OLD.issuing_body)
                    OR NOT (NEW.declaration_identifier <=> OLD.declaration_identifier)
                    OR NOT (NEW.evidence_type <=> OLD.evidence_type)
                    OR NOT (NEW.topic_code <=> OLD.topic_code)
                    OR NOT (NEW.standard_reference <=> OLD.standard_reference)
                    OR NOT (NEW.applicable_market <=> OLD.applicable_market)
                    OR NOT (NEW.reference_url <=> OLD.reference_url)
                    OR NOT (NEW.issue_date <=> OLD.issue_date)
                    OR NOT (NEW.valid_from <=> OLD.valid_from)
                    OR NOT (NEW.valid_until <=> OLD.valid_until)
                    OR NOT (NEW.expires_at <=> OLD.expires_at)
                    OR NEW.original_filename != OLD.original_filename
                    OR NOT (NEW.safe_display_filename <=> OLD.safe_display_filename)
                    OR NEW.mime_type != OLD.mime_type
                    OR NEW.file_extension != OLD.file_extension
                    OR NEW.size_bytes != OLD.size_bytes
                    OR NEW.checksum_sha256 != OLD.checksum_sha256
                    OR NEW.storage_key != OLD.storage_key
                    OR NEW.created_by_user_id != OLD.created_by_user_id
                    OR NEW.created_at != OLD.created_at
                THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'product_document_versions content is immutable.\';
                END IF;
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
};
