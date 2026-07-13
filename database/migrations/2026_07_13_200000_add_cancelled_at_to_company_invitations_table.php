<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invitations', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('accepted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table): void {
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn('cancelled_at');
        });
    }
};
