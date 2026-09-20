<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_publication_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('passport_id')->constrained('product_passports')->restrictOnDelete();
            $table->foreignId('draft_version_id')->constrained('product_passport_versions')->restrictOnDelete();
            $table->unsignedInteger('expected_revision');
            $table->string('status', 20);
            $table->boolean('acknowledge_warnings')->default(false);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_version_id')->nullable()->constrained('product_passport_versions')->nullOnDelete();
            $table->text('request_note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'passport_pub_approvals_company_status_index');
            $table->index(['passport_id', 'status'], 'passport_pub_approvals_passport_status_index');
            $table->unique(['passport_id', 'draft_version_id', 'status'], 'passport_pub_approvals_open_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_publication_approvals');
    }
};
