<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_passport_versions', fn (Blueprint $table) => $table->json('readiness_profile_snapshot')->nullable());
        DB::unprepared("CREATE TRIGGER passport_profile_snapshot_immutable BEFORE UPDATE ON product_passport_versions FOR EACH ROW
            BEGIN IF OLD.status <> 'draft' AND NOT (NEW.readiness_profile_snapshot <=> OLD.readiness_profile_snapshot)
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published DPP profile snapshots are immutable'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS passport_profile_snapshot_immutable');
        Schema::table('product_passport_versions', fn (Blueprint $table) => $table->dropColumn('readiness_profile_snapshot'));
    }
};
