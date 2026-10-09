<?php

namespace App\Services\Dpp;

use App\Models\Catalog\Product;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Passports\CanonicalJsonEncoder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DppPublicationProfileCheck
{
    /** @param array<int, DppProfile> $frozen @return array{status: string, reason: string|null} */
    public function check(Product $product, ?ProductPassportVersion $draft, array $frozen): array
    {
        try {
            $current = app(DppProfileResolver::class)->resolve($product->fresh());
        } catch (ValidationException) {
            return ['status' => 'stale', 'reason' => 'current_profile_unavailable'];
        }

        $regulatory = fn (array $profiles): array => array_values(array_filter($profiles,
            fn (DppProfile $profile): bool => $profile->key !== 'nordipass-pilot'));
        if ($regulatory($current) === [] && $regulatory($frozen) === []) {
            return ['status' => 'compatible', 'reason' => null];
        }
        if (! is_array($draft?->readiness_profile_snapshot)) {
            return ['status' => 'stale', 'reason' => 'regulatory_snapshot_missing'];
        }

        foreach ($current as $index => $profile) {
            $query = DppProfile::query()->whereKey($profile->getKey());
            if (DB::transactionLevel() > 0) {
                $query->sharedLock();
            }
            $locked = $query->first();
            if ($locked === null) {
                return ['status' => 'stale', 'reason' => 'current_profile_unavailable'];
            }
            try {
                app(DppProfileResolver::class)->assertApplicable($locked);
            } catch (ValidationException) {
                return ['status' => 'stale', 'reason' => 'current_profile_unavailable'];
            }
            $requirements = $locked->requirements()->orderBy('key');
            if (DB::transactionLevel() > 0) {
                $requirements->sharedLock();
            }
            $locked->setRelation('requirements', $requirements->get());
            $current[$index] = $locked;
        }

        return $this->fingerprint($frozen) === $this->fingerprint($current)
            ? ['status' => 'current', 'reason' => null]
            : ['status' => 'stale', 'reason' => 'regulatory_requirements_changed'];
    }

    /** @param array<int, DppProfile> $profiles */
    private function fingerprint(array $profiles): string
    {
        return app(CanonicalJsonEncoder::class)->hash(array_map(function (DppProfile $profile): array {
            return [
                'key' => $profile->key, 'version' => $profile->version, 'status' => $profile->status,
                'jurisdiction' => $profile->jurisdiction, 'product_scope' => $profile->product_scope,
                'effective_from' => $profile->effective_from?->toDateString(),
                'effective_to' => $profile->effective_to?->toDateString(), 'metadata' => $profile->metadata,
                'requirements' => $profile->requirements->sortBy('key')->map(function (DppRequirement $requirement): array {
                    $attributes = $requirement->attributesToArray();
                    unset($attributes['id'], $attributes['dpp_profile_id'], $attributes['created_at'], $attributes['updated_at']);
                    foreach (['source_version', 'effective_from', 'effective_to'] as $key) {
                        $attributes[$key] ??= null;
                    }

                    return $attributes;
                })->values()->all(),
            ];
        }, $profiles));
    }
}
