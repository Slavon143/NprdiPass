<?php

use App\Enums\Jobs\JobStatusState;
use App\Enums\Notifications\NotificationSeverity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('preference_key');
            $table->string('severity')->default(NotificationSeverity::Info->value);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->json('payload')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'idempotency_key'], 'notifications_company_user_idem_unique');
            $table->index(['company_id', 'user_id', 'read_at']);
            $table->index(['company_id', 'type', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'type']);
        });

        Schema::create('job_statuses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('job_statuses')->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('queue')->default('default');
            $table->string('status')->default(JobStatusState::Pending->value);
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->nullable();
            $table->json('payload_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('idempotency_key');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_statuses');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
