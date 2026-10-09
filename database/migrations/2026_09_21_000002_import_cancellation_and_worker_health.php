<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE import_jobs DROP CONSTRAINT import_jobs_status_check');
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_status_check CHECK (status IN ('uploaded', 'previewed', 'processing', 'completed', 'failed', 'cancelled'))");
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->timestamp('cancel_requested_at')->nullable()->after('failure_message');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_jobs DROP CONSTRAINT import_jobs_status_check');
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_status_check CHECK (status IN ('uploaded', 'previewed', 'processing', 'completed', 'failed'))");
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
