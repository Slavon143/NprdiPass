<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_passports', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_draft_version_id')->nullable()->after('enabled_languages');
            $table->unsignedBigInteger('current_published_version_id')->nullable()->after('current_draft_version_id');
        });

        DB::statement('ALTER TABLE product_passports ADD INDEX product_passports_published_version_pointer_index (company_id, id, current_published_version_id)');
        DB::statement('ALTER TABLE product_passports ADD INDEX product_passports_draft_version_pointer_index (company_id, id, current_draft_version_id)');

        DB::statement('ALTER TABLE product_passports ADD CONSTRAINT product_passports_draft_version_pointer_foreign FOREIGN KEY (company_id, id, current_draft_version_id) REFERENCES product_passport_versions(company_id, passport_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE product_passports ADD CONSTRAINT product_passports_published_version_pointer_foreign FOREIGN KEY (company_id, id, current_published_version_id) REFERENCES product_passport_versions(company_id, passport_id, id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE product_passports ADD CONSTRAINT product_passports_pointers_distinct_check CHECK (current_draft_version_id IS NULL OR current_published_version_id IS NULL OR current_draft_version_id <> current_published_version_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_passports DROP CHECK product_passports_pointers_distinct_check');

        Schema::table('product_passports', function (Blueprint $table): void {
            $table->dropForeign('product_passports_published_version_pointer_foreign');
            $table->dropForeign('product_passports_draft_version_pointer_foreign');
            $table->dropIndex('product_passports_published_version_pointer_index');
            $table->dropIndex('product_passports_draft_version_pointer_index');
            $table->dropColumn('current_published_version_id');
            $table->dropColumn('current_draft_version_id');
        });
    }
};
