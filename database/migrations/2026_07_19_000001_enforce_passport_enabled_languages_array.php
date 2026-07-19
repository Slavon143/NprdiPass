<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE product_passports
            SET enabled_languages = JSON_EXTRACT(JSON_UNQUOTE(enabled_languages), '$')
            WHERE JSON_TYPE(enabled_languages) = 'STRING'
              AND JSON_VALID(JSON_UNQUOTE(enabled_languages)) = 1
              AND JSON_TYPE(JSON_EXTRACT(JSON_UNQUOTE(enabled_languages), '$')) = 'ARRAY'
            SQL);

        DB::statement(<<<'SQL'
            UPDATE product_passports
            SET enabled_languages = JSON_ARRAY(default_language)
            WHERE JSON_TYPE(enabled_languages) <> 'ARRAY'
               OR JSON_LENGTH(enabled_languages) = 0
               OR JSON_CONTAINS(enabled_languages, JSON_QUOTE(default_language)) = 0
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE product_passports
            ADD CONSTRAINT product_passports_enabled_languages_check
            CHECK (
                JSON_TYPE(enabled_languages) = 'ARRAY'
                AND JSON_LENGTH(enabled_languages) > 0
                AND JSON_CONTAINS(enabled_languages, JSON_QUOTE(default_language)) = 1
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_passports DROP CHECK product_passports_enabled_languages_check');
    }
};
