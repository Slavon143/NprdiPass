<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->uuid('batch_uuid')->nullable()->after('properties');
            $table->string('ip_address', 45)->nullable()->after('batch_uuid');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->string('request_id', 100)->nullable()->after('user_agent');

            $table->index(['company_id', 'created_at'], 'activity_log_company_created_index');
            $table->index(['event', 'created_at'], 'activity_log_event_created_index');
            $table->index(
                ['causer_type', 'causer_id', 'created_at'],
                'activity_log_causer_created_index',
            );
            $table->index(
                ['subject_type', 'subject_id', 'created_at'],
                'activity_log_subject_created_index',
            );
            $table->index('request_id', 'activity_log_request_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_company_created_index');
            $table->dropIndex('activity_log_event_created_index');
            $table->dropIndex('activity_log_causer_created_index');
            $table->dropIndex('activity_log_subject_created_index');
            $table->dropIndex('activity_log_request_id_index');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['batch_uuid', 'ip_address', 'user_agent', 'request_id']);
        });
    }
};
