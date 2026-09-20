<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROFILE = 'nordipass-pilot';

    private const V1_FINGERPRINT = 'f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569';

    private const V2_FINGERPRINT = 'cba5078e465bba66f53ffcb613a7975a9ca4d5c8dd7888c72dcac988bd95e5b5';

    public function up(): void
    {
        DB::table('product_passport_versions')
            ->where('status', 'draft')
            ->where(function ($query): void {
                $query->whereNull('readiness_profile')
                    ->orWhere('readiness_profile', self::PROFILE);
            })
            ->where(function ($query): void {
                $query->whereNull('readiness_profile_version')
                    ->orWhere('readiness_profile_version', '<=', 1);
            })
            ->update([
                'readiness_profile' => self::PROFILE,
                'readiness_profile_version' => 2,
                'readiness_rule_set_fingerprint' => self::V2_FINGERPRINT,
            ]);
    }

    public function down(): void
    {
        DB::table('product_passport_versions')
            ->where('status', 'draft')
            ->where('readiness_profile', self::PROFILE)
            ->where('readiness_profile_version', 2)
            ->where('readiness_rule_set_fingerprint', self::V2_FINGERPRINT)
            ->update([
                'readiness_profile_version' => 1,
                'readiness_rule_set_fingerprint' => self::V1_FINGERPRINT,
            ]);
    }
};
