<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortnox_sync_runs', function (Blueprint $table): void {
            $table->char('dedupe_key', 64)->nullable()->after('idempotency_key');
            $table->timestamp('watermark_before')->nullable()->after('dedupe_key');
            $table->unsignedInteger('cursor_page')->nullable()->after('watermark_before');
            $table->boolean('pagination_complete')->default(false)->after('cursor_page');

            $table->index(['company_id', 'dedupe_key'], 'fortnox_runs_company_dedupe_index');
        });
    }

    public function down(): void
    {
        Schema::table('fortnox_sync_runs', function (Blueprint $table): void {
            $table->dropIndex('fortnox_runs_company_dedupe_index');
            $table->dropColumn(['dedupe_key', 'watermark_before', 'cursor_page', 'pagination_complete']);
        });
    }
};
