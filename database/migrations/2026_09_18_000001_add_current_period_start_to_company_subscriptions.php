<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table): void {
            $table->timestamp('current_period_start')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('current_period_start');
        });
    }
};
