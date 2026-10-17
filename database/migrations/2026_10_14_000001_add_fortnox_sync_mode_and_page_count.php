<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortnox_sync_runs', function (Blueprint $table): void {
            // Historical runs do not have an observed mode/page count.
            $table->enum('sync_mode', ['incremental', 'full'])->nullable();
            $table->unsignedInteger('pages_fetched')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fortnox_sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['sync_mode', 'pages_fetched']);
        });
    }
};
