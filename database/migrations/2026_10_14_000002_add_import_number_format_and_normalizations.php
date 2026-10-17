<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->enum('number_format', ['dot', 'comma'])->default('dot');
        });
        Schema::table('import_records', function (Blueprint $table): void {
            $table->json('normalizations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_records', fn (Blueprint $table) => $table->dropColumn('normalizations'));
        Schema::table('import_jobs', fn (Blueprint $table) => $table->dropColumn('number_format'));
    }
};
