<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Platform\FeatureFlagOverride;

class FeatureFlagManager
{
    /** @return list<string> */
    public function knownFlags(): array
    {
        return array_keys((array) config('features', []));
    }

    public function enabled(string $flag, ?Company $company = null): bool
    {
        if ($company instanceof Company) {
            $companyOverride = FeatureFlagOverride::query()
                ->where('company_id', $company->getKey())
                ->where('flag', $flag)
                ->first();

            if ($companyOverride instanceof FeatureFlagOverride) {
                return $companyOverride->enabled;
            }
        }

        $globalOverride = FeatureFlagOverride::query()
            ->whereNull('company_id')
            ->where('flag', $flag)
            ->first();

        if ($globalOverride instanceof FeatureFlagOverride) {
            return $globalOverride->enabled;
        }

        return (bool) config("features.{$flag}", false);
    }
}
