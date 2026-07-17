<?php

namespace App\Services\Passports\Localization;

use App\Data\Passports\DppSectionDefinition;
use App\Data\Passports\Localization\TranslationCompletenessResult;
use App\Enums\Passports\DppSectionKey;
use App\Services\Passports\DppSchemaRegistry;

class PassportTranslationCompletenessEvaluator
{
    public function __construct(
        private readonly DppSchemaRegistry $schemaRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, TranslationCompletenessResult>
     */
    public function evaluate(array $payload, array $enabledLocales, ?string $defaultLocale = null): array
    {
        $sections = $this->schemaRegistry->sections();
        $enabledSections = $payload['enabled_sections'] ?? [];
        $translations = $payload['translations'] ?? [];
        $results = [];

        foreach ($enabledLocales as $locale) {
            $results[$locale] = $this->evaluateLocale(
                $translations[$locale] ?? [],
                $enabledSections,
                $sections,
                $locale,
                $defaultLocale,
            );
        }

        return $results;
    }

    /**
     * @param  array<string, array<string, mixed>>  $localeTranslations
     * @param  string[]  $enabledSections
     * @param  array<string, DppSectionDefinition>  $sections
     */
    private function evaluateLocale(
        array $localeTranslations,
        array $enabledSections,
        array $sections,
        string $locale,
        ?string $defaultLocale,
    ): TranslationCompletenessResult {
        $requiredTotal = 0;
        $requiredComplete = 0;
        $optionalTotal = 0;
        $optionalComplete = 0;
        $missing = [];

        foreach ($enabledSections as $sectionKey) {
            $section = $sections[$sectionKey] ?? null;

            if ($section === null) {
                continue;
            }

            $sectionTranslations = $localeTranslations[$sectionKey] ?? [];
            $sectionEnum = DppSectionKey::tryFrom($sectionKey);

            foreach ($section->fields as $field) {
                if (! $field->translatable) {
                    continue;
                }

                $isRequired = $this->isRequiredTranslatableField($field->key, $sectionKey);
                $value = $sectionTranslations[$field->key] ?? null;
                $hasValue = $value !== null && $value !== '' && $value !== [];

                if ($isRequired) {
                    $requiredTotal++;

                    if ($hasValue) {
                        $requiredComplete++;
                    } else {
                        $missing[] = [
                            'section' => $sectionKey,
                            'field' => $field->key,
                            'required' => true,
                        ];
                    }
                } else {
                    $optionalTotal++;

                    if ($hasValue) {
                        $optionalComplete++;
                    }
                }
            }
        }

        $total = $requiredTotal + $optionalTotal;
        $complete = $requiredComplete + $optionalComplete;
        $completion = $total > 0 ? (int) round(($complete / $total) * 100) : 0;

        $status = $this->determineStatus($requiredComplete, $requiredTotal, $complete, $total);

        return new TranslationCompletenessResult(
            locale: $locale,
            status: $status,
            completion: $completion,
            requiredTotal: $requiredTotal,
            requiredComplete: $requiredComplete,
            optionalTotal: $optionalTotal,
            optionalComplete: $optionalComplete,
            missing: $missing,
        );
    }

    private function determineStatus(int $requiredComplete, int $requiredTotal, int $complete, int $total): string
    {
        if ($total === 0) {
            return 'not_started';
        }

        if ($requiredComplete === 0 && $complete === 0) {
            return 'not_started';
        }

        if ($requiredComplete < $requiredTotal) {
            return 'incomplete';
        }

        if ($requiredComplete === $requiredTotal) {
            return 'complete';
        }

        return 'incomplete';
    }

    /**
     * Translatable fields that must be filled for a language to be considered complete.
     */
    private function isRequiredTranslatableField(string $fieldKey, string $sectionKey): bool
    {
        // Core sections with required translatable fields
        if ($sectionKey === DppSectionKey::Identity->value) {
            return in_array($fieldKey, ['public_name', 'public_description'], true);
        }

        if ($sectionKey === DppSectionKey::ManufacturerAndOperator->value) {
            return in_array($fieldKey, ['manufacturer_display_name', 'responsible_operator_display_name'], true);
        }

        if ($sectionKey === DppSectionKey::Safety->value) {
            return in_array($fieldKey, ['warnings', 'storage_instructions', 'emergency_instructions'], true);
        }

        if ($sectionKey === DppSectionKey::RecyclingAndDisposal->value) {
            return in_array($fieldKey, ['recycling_instructions', 'disposal_instructions'], true);
        }

        // Optional sections: no required translatable fields by default
        return false;
    }
}
