<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_passport_id');
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->string('operation', 50);
            $table->string('status', 20)->default('processing');
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('product_passport_id')->references('id')->on('product_passports')->onDelete('restrict');
            $table->foreign('published_version_id')->references('id')->on('product_passport_versions')->onDelete('set null');

            $table->unique(
                ['company_id', 'operation', 'idempotency_key'],
                'publ_idempotency_company_op_key_unique',
            );

            $table->index('expires_at', 'publ_idempotency_expires_index');
        });

        DB::statement("ALTER TABLE publication_idempotency_records ADD CONSTRAINT publ_idempotency_status_check CHECK (status IN ('processing', 'completed', 'failed'))");
        DB::statement("ALTER TABLE publication_idempotency_records ADD CONSTRAINT publ_idempotency_fingerprint_check CHECK (request_fingerprint REGEXP '^[0-9a-fA-F]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_idempotency_records');
    }
};
