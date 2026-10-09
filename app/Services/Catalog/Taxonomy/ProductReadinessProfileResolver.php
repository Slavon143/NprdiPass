<?php

namespace App\Services\Catalog\Taxonomy;

use App\Data\Passports\Readiness\ReadinessProfileDefinition;
use App\Models\Catalog\Product;
use App\Services\Dpp\DppProfileResolver;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Validation\ValidationException;

class ProductReadinessProfileResolver
{
    public function __construct(private readonly ReadinessProfileRepository $profiles) {}

    public function resolve(Product $product): ReadinessProfileDefinition
    {
        $assigned = $product->dppProfiles()->orderByPivot('priority')->orderBy('dpp_profiles.id')->first();
        if ($assigned !== null) {
            app(DppProfileResolver::class)->assertApplicable($assigned);

            return $this->profiles->for($assigned->key, $assigned->version);
        }
        $product->loadMissing('primaryCategory.activeTemplate');
        app(DppProfileResolver::class)->assertCompleteSelection($product->readiness_profile_override, $product->readiness_profile_version_override);

        if (is_string($product->readiness_profile_override) && $product->readiness_profile_version_override !== null) {
            return $this->verified(
                $product->readiness_profile_override,
                (int) $product->readiness_profile_version_override,
                $product->readiness_rule_set_fingerprint_override,
            );
        }

        $template = $product->primaryCategory?->activeTemplate;

        if ($template !== null) {
            return $this->verified(
                $template->readiness_profile,
                (int) $template->readiness_profile_version,
                $template->readiness_rule_set_fingerprint,
            );
        }

        return $this->profiles->active();
    }

    private function verified(string $code, int $version, mixed $fingerprint): ReadinessProfileDefinition
    {
        $profile = $this->profiles->for($code, $version);

        if (! is_string($fingerprint) || ! hash_equals($profile->fingerprint, $fingerprint)) {
            throw ValidationException::withMessages([
                'readiness_profile' => 'The assigned readiness profile fingerprint is invalid.',
            ]);
        }

        return $profile;
    }
}
