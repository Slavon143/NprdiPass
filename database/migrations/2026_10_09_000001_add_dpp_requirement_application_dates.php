<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpp_requirements', function (Blueprint $table): void {
            $table->string('source_version', 100)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpp_requirements', function (Blueprint $table): void {
            $table->dropColumn(['source_version', 'effective_from', 'effective_to']);
        });
    }
};
