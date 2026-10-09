<?php

namespace App\Services\Dpp;

use App\Models\Catalog\Product;
use App\Models\DppProfile;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DppProfileResolver
{
    /** @param array<string, DppProfile>|null $catalogue @return array<int, DppProfile> */
    public function resolve(Product $product, ?array $catalogue = null): array
    {
        /** @var Collection<int, DppProfile> $assigned */
        $assigned = $catalogue !== null ? $product->dppProfiles : $product->dppProfiles()
            ->orderByPivot('priority')
            ->orderBy('dpp_profiles.id')
            ->get();
        if ($assigned->isNotEmpty()) {
            foreach ($assigned as $profile) {
                $this->assertApplicable($profile);
            }

            return $assigned->all();
        }

        $product->loadMissing('primaryCategory.activeTemplate');
        $code = $product->readiness_profile_override;
        $version = $product->readiness_profile_version_override;
        $this->assertCompleteSelection($code, $version);
        if ($code === null && $version === null) {
            $template = $product->primaryCategory?->activeTemplate;
            $code = $template?->readiness_profile;
            $version = $template?->readiness_profile_version;
            $this->assertCompleteSelection($code, $version);
        }

        if (is_string($code) && is_int($version)) {
            $profile = $catalogue !== null ? ($catalogue[DppReadinessProfileBatch::key($code, $version)] ?? null)
                : DppProfile::query()->where('key', $code)->where('version', $version)->first();
            if ($profile instanceof DppProfile) {
                $this->assertApplicable($profile);

                return [$profile];
            }
            throw ValidationException::withMessages(['readiness_profile' => 'The assigned DPP profile version does not exist.']);
        }

        $fallback = $catalogue !== null ? ($catalogue[DppReadinessProfileBatch::key(
            (string) config('passport_readiness.profile', 'nordipass-pilot'), (int) config('passport_readiness.profile_version', 1),
        )] ?? null) : DppProfile::query()
            ->where('key', config('passport_readiness.profile', 'nordipass-pilot'))
            ->where('version', (int) config('passport_readiness.profile_version', 1))
            ->orderByDesc('version')
            ->first();

        if ($fallback instanceof DppProfile) {
            $this->assertApplicable($fallback);
        } else {
            // Config-only legacy profiles remain supported, but an unknown
            // fallback must not resolve to an empty regulatory catalogue.
            app(ReadinessProfileRepository::class)->active();
        }

        return $fallback instanceof DppProfile ? [$fallback] : [];
    }

    public function assertApplicable(DppProfile $profile): void
    {
        $today = CarbonImmutable::today();
        if ($profile->status !== 'active'
            || ($profile->effective_from !== null && $profile->effective_from->greaterThan($today))
            || ($profile->effective_to !== null && $profile->effective_to->lessThan($today))) {
            throw ValidationException::withMessages(['readiness_profile' => 'The assigned DPP profile is not currently applicable.']);
        }
    }

    public function assertCompleteSelection(?string $code, ?int $version): void
    {
        if ($code === null && $version === null) {
            return;
        }
        if ($code === null || trim($code) === '' || $version === null || $version < 1) {
            throw ValidationException::withMessages(['readiness_profile' => 'A DPP profile key and positive version must be selected together.']);
        }
    }
}
