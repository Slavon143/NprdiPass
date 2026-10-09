<?php

namespace App\Services\Dpp;

use App\Models\DppProfile;
use App\Models\DppRequirement;
use LogicException;

class EffectiveDppRequirementSet
{
    /** @param array<int, DppProfile> $profiles @return array<int, DppRequirement> */
    public function resolve(array $profiles, bool $refresh = true): array
    {
        $effective = [];
        foreach ($profiles as $profile) {
            if ($profile->exists && $refresh) {
                $profile->load('requirements');
            }
            foreach ($profile->requirements->sortBy(fn ($requirement) => [$requirement->sort_order, $requirement->getKey()]) as $requirement) {
                $requirement->setRelation('profile', $profile);
                $key = $requirement->key;
                if (! isset($effective[$key])) {
                    $effective[$key] = $requirement;

                    continue;
                }

                if ($this->signature($effective[$key]) !== $this->signature($requirement)) {
                    throw new LogicException("Conflicting DPP requirement: {$key}.");
                }
            }
        }

        return array_values($effective);
    }

    private function signature(DppRequirement $requirement): string
    {
        return serialize([
            $requirement->title,
            $requirement->description,
            $requirement->level->value,
            $requirement->section,
            $requirement->field,
            $requirement->source,
            $requirement->source_reference,
            $requirement->source_version,
            $requirement->effective_from?->toDateString(),
            $requirement->effective_to?->toDateString(),
            $requirement->applies_because,
            $requirement->rule_code,
            $requirement->rule_class,
            $requirement->evaluation_config,
            $requirement->action_target,
        ]);
    }
}
