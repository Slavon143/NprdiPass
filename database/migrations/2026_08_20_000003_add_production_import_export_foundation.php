<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('name_normalized');
            $table->string('entity_type')->default('product');
            $table->json('mapping');
            $table->json('defaults')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'import_tpl_company_id_unique');
            $table->unique(['company_id', 'name_normalized'], 'import_tpl_company_name_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('mapping_template_id')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->string('entity_type')->default('product');
            $table->string('mode')->default('upsert');
            $table->string('status')->default('uploaded');
            $table->string('source_format', 8);
            $table->string('source_filename');
            $table->string('source_path', 1024);
            $table->string('source_mime', 191);
            $table->unsignedBigInteger('source_size');
            $table->char('source_sha256', 64);
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->char('plan_fingerprint', 64)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->unsignedInteger('chunk_size')->default(250);
            $table->unsignedInteger('next_row')->default(2);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'import_jobs_company_id_unique');
            $table->unique(['company_id', 'idempotency_key'], 'import_jobs_company_idempotency_unique');
            $table->index(['company_id', 'status', 'created_at'], 'import_jobs_company_status_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_template_fk FOREIGN KEY (company_id, mapping_template_id) REFERENCES import_mapping_templates(company_id, id) ON DELETE RESTRICT');

        Schema::create('import_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('import_job_id');
            $table->unsignedInteger('row_number');
            $table->char('row_fingerprint', 64);
            $table->json('source_data');
            $table->json('mapped_data');
            $table->string('status')->default('valid');
            $table->string('operation')->nullable();
            $table->unsignedBigInteger('target_product_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'import_job_id', 'row_number'], 'import_records_job_row_unique');
            $table->unique(['company_id', 'id'], 'import_records_company_id_unique');
            $table->index(['company_id', 'import_job_id', 'status', 'row_number'], 'import_records_resume_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE import_records ADD CONSTRAINT import_records_job_fk FOREIGN KEY (company_id, import_job_id) REFERENCES import_jobs(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE import_records ADD CONSTRAINT import_records_product_fk FOREIGN KEY (company_id, target_product_id) REFERENCES products(company_id, id) ON DELETE RESTRICT');

        Schema::create('import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('import_job_id');
            $table->unsignedBigInteger('import_record_id')->nullable();
            $table->unsignedInteger('row_number');
            $table->string('field')->nullable();
            $table->string('code');
            $table->text('message');
            $table->timestamps();

            $table->index(['company_id', 'import_job_id', 'row_number'], 'import_errors_job_row_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE import_errors ADD CONSTRAINT import_errors_job_fk FOREIGN KEY (company_id, import_job_id) REFERENCES import_jobs(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE import_errors ADD CONSTRAINT import_errors_record_fk FOREIGN KEY (company_id, import_record_id) REFERENCES import_records(company_id, id) ON DELETE CASCADE');

        DB::statement("ALTER TABLE import_mapping_templates ADD CONSTRAINT import_tpl_entity_check CHECK (entity_type IN ('product'))");
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_entity_check CHECK (entity_type IN ('product'))");
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_mode_check CHECK (mode IN ('create', 'update', 'upsert'))");
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_status_check CHECK (status IN ('uploaded', 'previewed', 'processing', 'completed', 'failed'))");
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_format_check CHECK (source_format IN ('csv', 'xlsx'))");
        DB::statement('ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_chunk_check CHECK (chunk_size BETWEEN 1 AND 1000)');
        DB::statement("ALTER TABLE import_records ADD CONSTRAINT import_records_status_check CHECK (status IN ('valid', 'invalid', 'applied', 'skipped', 'failed'))");
        DB::statement("ALTER TABLE import_records ADD CONSTRAINT import_records_operation_check CHECK (operation IS NULL OR operation IN ('create', 'update', 'skip'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('import_records');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('import_mapping_templates');
    }
};
