<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortnox_sync_runs', function (Blueprint $table): void {
            $table->timestamp('watermark_observed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fortnox_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('watermark_observed');
        });
    }
};
