<?php

namespace App\Services\Dpp;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessProfileDefinition;
use App\Models\Catalog\Product;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Services\Passports\Readiness\PassportReadinessRuleRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DppDraftProfileSnapshot
{
    public function capture(Product $product, ReadinessProfileDefinition $definition): array
    {
        $profiles = app(DppProfileResolver::class)->resolve($product);
        $requirements = app(EffectiveDppRequirementSet::class)->resolve($profiles);
        $rules = app(PassportReadinessRuleRegistry::class)->all($definition);
        foreach ($requirements as $requirement) {
            if (is_string($requirement->rule_class) && is_subclass_of($requirement->rule_class, PassportReadinessRule::class)) {
                $rules[] = app($requirement->rule_class);
            }
        }

        return ['version' => 1, 'definition' => get_object_vars($definition),
            'execution' => app(ReadinessExecutionProvenance::class)->capture($rules),
            'config' => config('passport_readiness'),
            'profiles' => array_map(function (DppProfile $profile): array {
                return ['attributes' => $profile->attributesToArray(),
                    'assignment' => $profile->relationLoaded('pivot') ? $profile->pivot->attributesToArray() : null,
                    'requirements' => $profile->requirements->map(fn ($requirement) => $requirement->attributesToArray())->all()];
            }, $profiles)];
    }

    /** @return array<int, DppProfile> */
    public function profiles(array $snapshot): array
    {
        if (($snapshot['version'] ?? null) !== 1) {
            throw new \LogicException('Unsupported DPP draft profile snapshot.');
        }

        return array_map(function (array $stored): DppProfile {
            $profile = new DppProfile;
            $profile->forceFill($stored['attributes']);
            $profile->setRelation('requirements', new Collection(array_map(function (array $attributes) use ($profile): DppRequirement {
                $requirement = new DppRequirement;
                $requirement->forceFill($attributes);
                $requirement->setRelation('profile', $profile);

                return $requirement;
            }, $stored['requirements'])));
            if (is_array($stored['assignment'])) {
                $profile->setRelation('pivot', (new Pivot)->forceFill($stored['assignment']));
            }

            return $profile;
        }, $snapshot['profiles']);
    }
}
