<?php

namespace App\Services\Passports\Readiness;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessProfileDefinition;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Services\Passports\CanonicalJsonEncoder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ReadinessProfileRepository
{
    public function __construct(private readonly CanonicalJsonEncoder $canonicalJsonEncoder) {}

    public function active(): ReadinessProfileDefinition
    {
        return $this->for(
            (string) config('passport_readiness.profile', 'nordipass-pilot'),
            (int) config('passport_readiness.profile_version', 1),
        );
    }

    public function for(string $code, int $version, ?DppProfile $catalogueProfile = null): ReadinessProfileDefinition
    {
        $profile = config("passport_readiness.profiles.{$code}");
        $versionConfig = is_array($profile) ? ($profile['versions'][$version] ?? null) : null;

        if (! is_array($versionConfig) && ($catalogueProfile !== null || Schema::hasTable('dpp_profiles'))) {
            $stored = $catalogueProfile ?? DppProfile::query()->where('key', $code)->where('version', $version)->with('requirements')->first();
            if ($stored !== null) {
                return $this->storedDefinition($stored);
            }
        }

        if (! is_array($profile) || ! is_array($versionConfig)) {
            throw new InvalidArgumentException("Unknown readiness profile version: {$code} v{$version}.");
        }

        $weights = $this->weights($versionConfig['weights'] ?? config('passport_readiness.score_weights'));
        $ruleClasses = $this->ruleClasses($versionConfig['rules'] ?? config('passport_readiness.rules', []));
        $ruleConfigurations = $this->ruleConfigurations($ruleClasses, $weights);
        $ruleSetVersion = (int) ($versionConfig['rule_set_version'] ?? config('passport_readiness.rule_set_version', 1));
        $scoreAlgorithm = (string) ($versionConfig['score_algorithm'] ?? config('passport_readiness.score_algorithm', 'weighted_ratio'));
        $scoreAlgorithmVersion = (int) ($versionConfig['score_algorithm_version'] ?? config('passport_readiness.score_algorithm_version', 1));
        $status = (string) ($versionConfig['status'] ?? $profile['status'] ?? 'active');

        if (! in_array($status, ['draft', 'active', 'deprecated'], true)) {
            throw new InvalidArgumentException("Invalid readiness profile status: {$status}.");
        }

        $fingerprintInput = [
            'profile' => $code,
            'profile_version' => $version,
            'rule_set_version' => $ruleSetVersion,
            'score_algorithm' => $scoreAlgorithm,
            'score_algorithm_version' => $scoreAlgorithmVersion,
            'weights' => $weights,
            'rules' => $ruleConfigurations,
        ];

        // The pilot profile has a legacy fingerprint contract used by stored
        // historical readiness results. New regulatory profiles include the
        // persisted requirement catalog in their fingerprint.
        if ($code !== 'nordipass-pilot' && ($catalogueProfile !== null || Schema::hasTable('dpp_profiles'))) {
            /** @var DppProfile|null $storedProfile */
            $storedProfile = $catalogueProfile ?? DppProfile::query()
                ->where('key', $code)
                ->where('version', $version)
                ->with(['requirements' => fn ($query) => $query->orderBy('key')])
                ->first();

            $requirements = $storedProfile?->requirements->sortBy('key')
                ->map(fn (DppRequirement $requirement): array => [
                    'key' => $requirement->key,
                    'level' => $requirement->level->value,
                    'section' => $requirement->section,
                    'field' => $requirement->field,
                    'source' => $requirement->source,
                    'source_reference' => $requirement->source_reference,
                    'source_version' => $requirement->source_version,
                    'effective_from' => $requirement->effective_from?->toDateString(),
                    'effective_to' => $requirement->effective_to?->toDateString(),
                    'rule_code' => $requirement->rule_code,
                    'evaluation_config' => $requirement->evaluation_config,
                ])->values()->all() ?? [];

            $fingerprintInput['requirements'] = $requirements;
        }

        $fingerprint = $this->canonicalJsonEncoder->hash($fingerprintInput);

        return new ReadinessProfileDefinition(
            code: $code,
            version: $version,
            name: (string) ($profile['name'] ?? $code),
            description: (string) ($profile['description'] ?? ''),
            status: $status,
            ruleSetVersion: $ruleSetVersion,
            scoreAlgorithm: $scoreAlgorithm,
            scoreAlgorithmVersion: $scoreAlgorithmVersion,
            weights: $weights,
            ruleClasses: $ruleClasses,
            fingerprint: $fingerprint,
            metadata: is_array($versionConfig['metadata'] ?? null) ? $versionConfig['metadata'] : [],
        );
    }

    /**
     * @return array{blocker: int, warning: int, recommendation: int}
     */
    private function weights(mixed $configured): array
    {
        $weights = [];

        foreach (ReadinessSeverity::cases() as $severity) {
            $weight = is_array($configured) ? ($configured[$severity->value] ?? null) : null;

            if (! is_int($weight) || $weight <= 0) {
                throw new InvalidArgumentException(
                    "Readiness score weight for {$severity->value} must be a positive integer.",
                );
            }

            $weights[$severity->value] = $weight;
        }

        return $weights;
    }

    private function storedDefinition(DppProfile $profile): ReadinessProfileDefinition
    {
        // Catalogue requirements drive domain evaluation; only publication
        // integrity rules are inherited by an independently defined profile.
        $classes = array_values(array_filter(config('passport_readiness.rules', []), function (string $class): bool {
            $rule = app($class);

            return in_array($rule->group(), [ReadinessRuleGroup::Passport,
                ReadinessRuleGroup::Documents,
                ReadinessRuleGroup::Media], true);
        }));
        $weights = $this->weights(config('passport_readiness.score_weights'));
        $attributes = $profile->attributesToArray();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $requirements = $profile->requirements->sortBy('key')->map(fn ($requirement) => $requirement->attributesToArray())->values()->all();

        return new ReadinessProfileDefinition($profile->key, $profile->version, $profile->name,
            $profile->description ?? '', $profile->status, $profile->version, 'weighted_ratio', 1,
            $weights, $classes, $this->canonicalJsonEncoder->hash(['profile' => $attributes, 'requirements' => $requirements,
                'integrity_rules' => $this->ruleConfigurations($classes, $weights)]), $profile->metadata ?? []);
    }

    /**
     * @return array<int, class-string>
     */
    private function ruleClasses(mixed $configured): array
    {
        if (! is_array($configured)) {
            throw new InvalidArgumentException('Readiness profile rules must be an array of rule classes.');
        }

        $ruleClasses = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! is_subclass_of($class, PassportReadinessRule::class, true)) {
                throw new InvalidArgumentException('Readiness profile contains an invalid rule class.');
            }

            $ruleClasses[] = $class;
        }

        if ($ruleClasses === []) {
            throw new InvalidArgumentException('Readiness profile must contain at least one rule.');
        }

        return $ruleClasses;
    }

    /**
     * @param  array<int, class-string>  $ruleClasses
     * @param  array{blocker: int, warning: int, recommendation: int}  $weights
     * @return array<int, array<string, mixed>>
     */
    private function ruleConfigurations(array $ruleClasses, array $weights): array
    {
        $seen = [];
        $rules = [];

        foreach ($ruleClasses as $index => $class) {
            /** @var PassportReadinessRule $rule */
            $rule = app($class);
            $code = $rule->code();

            if (isset($seen[$code])) {
                throw new InvalidArgumentException("Duplicate readiness rule code: {$code}.");
            }

            $seen[$code] = true;

            $rules[] = [
                'code' => $code,
                'class' => $class,
                'group' => $rule->group()->value,
                'severity' => $rule->severity()->value,
                'weight' => $weights[$rule->severity()->value],
                'enabled' => true,
                'sort_order' => $index + 1,
            ];
        }

        usort($rules, fn (array $a, array $b): int => [$a['group'], $a['code']] <=> [$b['group'], $b['code']]);

        return $rules;
    }
}
