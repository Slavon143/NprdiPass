<?php

namespace App\Services\Passports;

use App\Data\Passports\DppFieldDefinition;
use App\Data\Passports\DppSectionDefinition;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Passports\DppFieldType;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use Illuminate\Validation\ValidationException;

class DppPayloadValidator
{
    private DppSchemaRegistry $registry;

    private DppPayloadNormalizer $normalizer;

    public function __construct(DppSchemaRegistry $registry, DppPayloadNormalizer $normalizer)
    {
        $this->registry = $registry;
        $this->normalizer = $normalizer;
    }

    public const MAX_ENCODED_SIZE = 1_048_576; // 1 MiB

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function validateFullPayload(array $payload, Company $company, ?ProductPassport $passport = null): array
    {
        $errors = [];

        $this->validateTopLevelStructure($payload, $errors);
        $this->validateEnabledSections($payload, $errors);
        $this->validateDocumentReferences($payload['document_references'] ?? [], $company, $passport, $errors);

        if ($passport !== null && isset($payload['translations'])) {
            $this->validateTranslations($payload['translations'], $payload['enabled_sections'] ?? [], $passport->default_language, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $normalized = $this->normalizer->normalize($payload);
        $this->validateSizeLimit($normalized, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     *
     * @throws ValidationException
     */
    public function validateSectionPayload(string $sectionKey, array $sectionPayload, bool $checkTranslatable, string $locale = 'sv'): array
    {
        $sections = $this->registry->sections();
        $sectionDef = $sections[$sectionKey] ?? null;

        if ($sectionDef === null) {
            throw ValidationException::withMessages(['section' => ['Unknown section.']]);
        }

        $errors = [];

        if ($checkTranslatable) {
            $this->validateTranslatableSectionFields($sectionPayload, $sectionDef, $locale, $errors);
        } else {
            $this->validateNonTranslatableSectionFields($sectionPayload, $sectionDef, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $sectionPayload;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, string[]>  $errors
     */
    private function validateTopLevelStructure(array $rawPayload, array &$errors): void
    {
        $allowedTopLevelKeys = ['enabled_sections', 'data', 'translations', 'document_references'];

        foreach (array_keys($rawPayload) as $key) {
            if (! in_array($key, $allowedTopLevelKeys, true)) {
                $errors['payload'][] = "Unknown top-level key: {$key}.";
            }
        }

        if (isset($rawPayload['enabled_sections']) && ! is_array($rawPayload['enabled_sections'])) {
            $errors['enabled_sections'][] = 'enabled_sections must be an array.';
        }

        if (isset($rawPayload['data'])) {
            if (! is_array($rawPayload['data'])) {
                $errors['data'][] = 'data must be a key-value object.';
            } else {
                $this->validateUnknownSectionKeys($rawPayload['data'], $errors);
            }
        }

        if (isset($rawPayload['translations'])) {
            if (! is_array($rawPayload['translations'])) {
                $errors['translations'][] = 'translations must be a key-value object.';
            } else {
                $this->validateLocaleFormat(array_keys($rawPayload['translations']), $errors);
            }
        }

        if (isset($rawPayload['document_references'])) {
            if (! is_array($rawPayload['document_references'])) {
                $errors['document_references'][] = 'document_references must be an array.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string[]>  $errors
     */
    private function validateEnabledSections(array $payload, array &$errors): void
    {
        $rawSections = $payload['enabled_sections'] ?? [];

        if (! is_array($rawSections)) {
            return;
        }

        $sections = $this->registry->sections();
        $validKeys = array_keys($sections);
        $seen = [];

        foreach ($rawSections as $index => $section) {
            if (! is_string($section)) {
                $errors['enabled_sections'][] = "Section at index {$index} must be a string.";

                continue;
            }

            if (! in_array($section, $validKeys, true)) {
                $errors['enabled_sections'][] = "Unknown section: {$section}.";

                continue;
            }

            if (isset($seen[$section])) {
                $errors['enabled_sections'][] = "Duplicate section: {$section}.";

                continue;
            }

            $seen[$section] = true;
        }

        foreach ($sections as $key => $def) {
            if ($def->core && ! isset($seen[$key])) {
                $errors['enabled_sections'][] = "Core section '{$key}' cannot be disabled.";
            }
        }
    }

    /**
     * @param  array<int, mixed>  $refs
     * @param  array<string, string[]>  $errors
     */
    private function validateDocumentReferences(array $refs, Company $company, ?ProductPassport $passport, array &$errors): void
    {
        if (count($refs) > 100) {
            $errors['document_references'][] = 'Maximum 100 document references allowed.';
        }

        $validRoles = array_map(fn ($c) => $c->value, ProductDocumentType::cases());
        $seenUuids = [];

        foreach ($refs as $index => $ref) {
            if (! is_array($ref)) {
                $errors['document_references'][] = "Reference at index {$index} must be an object.";

                continue;
            }

            /** @var array<string, mixed> $ref */
            $uuid = isset($ref['document_uuid']) ? trim((string) $ref['document_uuid']) : '';

            if ($uuid === '') {
                $errors['document_references'][] = "Reference at index {$index} is missing document_uuid.";

                continue;
            }

            if (isset($seenUuids[$uuid])) {
                $errors['document_references'][] = "Duplicate document UUID '{$uuid}' in references.";

                continue;
            }

            $seenUuids[$uuid] = true;

            $role = isset($ref['role']) ? trim((string) $ref['role']) : 'other';

            if (! in_array($role, $validRoles, true)) {
                $errors['document_references'][] = "Unknown role '{$role}' for document reference at index {$index}.";

                continue;
            }

            if (isset($ref['display_order']) && (! is_int($ref['display_order']) || $ref['display_order'] < 0)) {
                $errors['document_references'][] = "display_order must be a non-negative integer at index {$index}.";
            }

            if ($passport !== null) {
                $this->validateDocumentBelongsToProduct($uuid, $company, $passport, $index, $errors);
            }
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateDocumentBelongsToProduct(string $uuid, Company $company, ProductPassport $passport, int $index, array &$errors): void
    {
        $document = ProductDocument::query()
            ->where('uuid', $uuid)
            ->forCompany($company)
            ->first();

        if ($document === null) {
            $errors['document_references'][] = "Document '{$uuid}' not found at index {$index}.";

            return;
        }

        if ((int) $document->getAttribute('product_id') !== (int) $passport->getAttribute('product_id')) {
            $errors['document_references'][] = "Document '{$uuid}' does not belong to this product at index {$index}.";
        }

        if (! $document->isActive()) {
            $errors['document_references'][] = "Document '{$uuid}' is not active at index {$index}.";
        }
    }

    /**
     * @param  array<mixed, mixed>  $translations
     * @param  string[]  $enabledSections
     * @param  array<string, string[]>  $errors
     */
    private function validateTranslations(array $translations, array $enabledSections, string $defaultLanguage, array &$errors): void
    {
        foreach ($translations as $locale => $localeData) {
            if (! is_string($locale) || ! $this->isValidLocale($locale)) {
                $errors['translations'][] = "Invalid locale format: {$locale}.";

                continue;
            }

            if (! is_array($localeData)) {
                $errors['translations'][] = "Locale '{$locale}' data must be an object.";

                continue;
            }

            /** @var array<string, mixed> $localeData */
            $sections = $this->registry->sections();

            foreach ($localeData as $sectionKey => $fields) {
                $sectionDef = $sections[$sectionKey] ?? null;

                if ($sectionDef === null) {
                    $errors['translations'][] = "Unknown section '{$sectionKey}' in locale '{$locale}'.";

                    continue;
                }

                if (! $sectionDef->translatable) {
                    $errors['translations'][] = "Section '{$sectionKey}' is not translatable in locale '{$locale}'.";

                    continue;
                }

                if (! in_array($sectionKey, $enabledSections, true)) {
                    $errors['translations'][] = "Section '{$sectionKey}' is not enabled in locale '{$locale}'.";

                    continue;
                }

                if (! is_array($fields)) {
                    $errors['translations'][] = "Section data for '{$sectionKey}' in locale '{$locale}' must be an object.";

                    continue;
                }

                /** @var array<string, mixed> $fields */
                $this->validateUntypedSectionFields($fields, $sectionDef, $errors);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string[]>  $errors
     */
    private function validateUnknownSectionKeys(array $data, array &$errors): void
    {
        $validKeys = array_keys($this->registry->sections());

        foreach (array_keys($data) as $key) {
            if (! in_array($key, $validKeys, true)) {
                $errors['data'][] = "Unknown section: {$key}.";
            }
        }
    }

    /**
     * @param  string[]  $locales
     * @param  array<string, string[]>  $errors
     */
    private function validateLocaleFormat(array $locales, array &$errors): void
    {
        foreach ($locales as $locale) {
            if (! $this->isValidLocale($locale)) {
                $errors['translations'][] = "Invalid locale format: {$locale}.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string[]>  $errors
     */
    private function validateTranslatableSectionFields(array $fields, DppSectionDefinition $sectionDef, string $locale, array &$errors): void
    {
        foreach ($sectionDef->fields as $fieldDef) {
            if (! $fieldDef->translatable) {
                continue;
            }

            if (! array_key_exists($fieldDef->key, $fields)) {
                continue;
            }

            $this->validateFieldValue($fieldDef->key, $fields[$fieldDef->key], $fieldDef, $errors);
        }

        foreach (array_keys($fields) as $fieldKey) {
            $known = false;

            foreach ($sectionDef->fields as $fieldDef) {
                if ($fieldDef->key === $fieldKey && $fieldDef->translatable) {
                    $known = true;
                    break;
                }
            }

            if (! $known) {
                $errors['fields'][] = "Unknown field '{$fieldKey}' in translatable section '{$sectionDef->key->value}'.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string[]>  $errors
     */
    private function validateNonTranslatableSectionFields(array $fields, DppSectionDefinition $sectionDef, array &$errors): void
    {
        foreach ($sectionDef->fields as $fieldDef) {
            if ($fieldDef->translatable) {
                continue;
            }

            if (! array_key_exists($fieldDef->key, $fields)) {
                continue;
            }

            $this->validateFieldValue($fieldDef->key, $fields[$fieldDef->key], $fieldDef, $errors);
        }

        foreach (array_keys($fields) as $fieldKey) {
            $known = false;

            foreach ($sectionDef->fields as $fieldDef) {
                if ($fieldDef->key === $fieldKey && ! $fieldDef->translatable) {
                    $known = true;
                    break;
                }
            }

            if (! $known) {
                $errors['fields'][] = "Unknown field '{$fieldKey}' in non-translatable section '{$sectionDef->key->value}'.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string[]>  $errors
     */
    private function validateUntypedSectionFields(array $fields, DppSectionDefinition $sectionDef, array &$errors): void
    {
        foreach (array_keys($fields) as $fieldKey) {
            $known = false;

            foreach ($sectionDef->fields as $fieldDef) {
                if ($fieldDef->key === $fieldKey) {
                    $known = true;
                    break;
                }
            }

            if (! $known) {
                $errors['fields'][] = "Unknown field '{$fieldKey}' in section '{$sectionDef->key->value}'.";
            }
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateFieldValue(string $fieldKey, mixed $value, DppFieldDefinition $def, array &$errors): void
    {
        if ($value === null) {
            if (! $def->nullable) {
                $errors[$fieldKey][] = "Field '{$fieldKey}' cannot be null.";
            }

            return;
        }

        match ($def->type) {
            DppFieldType::ShortText, DppFieldType::LongText => $this->validateTextField($fieldKey, $value, $def, $errors),
            DppFieldType::Boolean => $this->validateBooleanField($fieldKey, $value, $errors),
            DppFieldType::Integer => $this->validateIntegerField($fieldKey, $value, $def, $errors),
            DppFieldType::Decimal => $this->validateDecimalField($fieldKey, $value, $def, $errors),
            DppFieldType::Date => $this->validateDateField($fieldKey, $value, $errors),
            DppFieldType::Email => $this->validateEmailField($fieldKey, $value, $errors),
            DppFieldType::Url => $this->validateUrlField($fieldKey, $value, $errors),
            DppFieldType::CountryCode => $this->validateCountryCodeField($fieldKey, $value, $errors),
            DppFieldType::StringList => $this->validateStringListField($fieldKey, $value, $def, $errors),
            DppFieldType::MaterialList => $this->validateMaterialListField($fieldKey, $value, $def, $errors),
        };
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateTextField(string $fieldKey, mixed $value, DppFieldDefinition $def, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a string.";

            return;
        }

        if ($def->maxLength !== null && mb_strlen($value) > $def->maxLength) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be at most {$def->maxLength} characters.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateBooleanField(string $fieldKey, mixed $value, array &$errors): void
    {
        if (! is_bool($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a boolean.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateIntegerField(string $fieldKey, mixed $value, DppFieldDefinition $def, array &$errors): void
    {
        if (! is_int($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be an integer.";

            return;
        }

        if ($def->min !== null && $value < $def->min) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be at least {$def->min}.";
        }

        if ($def->max !== null && $value > $def->max) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be at most {$def->max}.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateDecimalField(string $fieldKey, mixed $value, DppFieldDefinition $def, array &$errors): void
    {
        if (! is_int($value) && ! is_float($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a number.";

            return;
        }

        $numeric = (float) $value;

        if ($def->min !== null && $numeric < $def->min) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be at least {$def->min}.";
        }

        if ($def->max !== null && $numeric > $def->max) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be at most {$def->max}.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateDateField(string $fieldKey, mixed $value, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a string.";

            return;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be in YYYY-MM-DD format.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateEmailField(string $fieldKey, mixed $value, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a string.";

            return;
        }

        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a valid email.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateUrlField(string $fieldKey, mixed $value, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a string.";

            return;
        }

        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a valid URL.";
        }

        $parsed = parse_url($value);

        if ($parsed !== false && (isset($parsed['user']) || isset($parsed['pass']))) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must not contain credentials.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateCountryCodeField(string $fieldKey, mixed $value, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be a string.";

            return;
        }

        if (! preg_match('/^[A-Z]{2}$/', $value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be an ISO 3166-1 alpha-2 country code.";
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateStringListField(string $fieldKey, mixed $value, DppFieldDefinition $def, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be an array.";

            return;
        }

        if ($def->maxItems !== null && count($value) > $def->maxItems) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must have at most {$def->maxItems} items.";
        }

        foreach ($value as $index => $item) {
            if (! is_string($item)) {
                $errors[$fieldKey][] = "Item at index {$index} in '{$fieldKey}' must be a string.";

                continue;
            }

            if ($def->maxLength !== null && mb_strlen($item) > $def->maxLength) {
                $errors[$fieldKey][] = "Item at index {$index} in '{$fieldKey}' must be at most {$def->maxLength} characters.";
            }
        }
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function validateMaterialListField(string $fieldKey, mixed $value, DppFieldDefinition $def, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must be an array.";

            return;
        }

        if ($def->maxItems !== null && count($value) > $def->maxItems) {
            $errors[$fieldKey][] = "Field '{$fieldKey}' must have at most {$def->maxItems} items.";
        }

        $seenNames = [];
        $totalPercentage = 0.0;

        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                $errors[$fieldKey][] = "Item at index {$index} in '{$fieldKey}' must be an object.";

                continue;
            }

            /** @var array<string, mixed> $item */
            if (! isset($item['name']) || ! is_string($item['name']) || trim($item['name']) === '') {
                $errors[$fieldKey][] = "Item at index {$index} in '{$fieldKey}' must have a non-empty 'name'.";

                continue;
            }

            $normalizedName = mb_strtolower(trim($item['name']));

            if (isset($seenNames[$normalizedName])) {
                $errors[$fieldKey][] = "Duplicate material name '{$item['name']}' in '{$fieldKey}'.";
            }

            $seenNames[$normalizedName] = true;

            if (isset($item['percentage'])) {
                if (! is_numeric($item['percentage'])) {
                    $errors[$fieldKey][] = "Material 'percentage' at index {$index} must be a number.";
                } else {
                    $pct = (float) $item['percentage'];

                    if ($pct < 0 || $pct > 100) {
                        $errors[$fieldKey][] = "Material 'percentage' at index {$index} must be between 0 and 100.";
                    } else {
                        $totalPercentage += $pct;
                    }
                }
            }

            if (isset($item['recycled_content_percentage'])) {
                if (! is_numeric($item['recycled_content_percentage'])) {
                    $errors[$fieldKey][] = "Material 'recycled_content_percentage' at index {$index} must be a number.";
                } else {
                    $pct = (float) $item['recycled_content_percentage'];

                    if ($pct < 0 || $pct > 100) {
                        $errors[$fieldKey][] = "Material 'recycled_content_percentage' at index {$index} must be between 0 and 100.";
                    }
                }
            }

            if (isset($item['hazardous']) && ! is_bool($item['hazardous'])) {
                $errors[$fieldKey][] = "Material 'hazardous' at index {$index} must be a boolean.";
            }

            if (isset($item['country_of_origin'])) {
                if (! is_string($item['country_of_origin'])) {
                    $errors[$fieldKey][] = "Material 'country_of_origin' at index {$index} must be a string.";
                } elseif (! preg_match('/^[A-Z]{2}$/', $item['country_of_origin'])) {
                    $errors[$fieldKey][] = "Material 'country_of_origin' at index {$index} must be an ISO 3166-1 alpha-2 country code.";
                }
            }
        }

        if ($totalPercentage > 100.0) {
            $errors[$fieldKey][] = "Material percentages sum ({$totalPercentage}%) exceeds 100%.";
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, string[]>  $errors
     */
    private function validateSizeLimit(array $normalized, array &$errors): void
    {
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $errors['payload'][] = 'Failed to encode payload for size check.';

            return;
        }

        if (strlen($json) > self::MAX_ENCODED_SIZE) {
            $errors['payload'][] = 'Encoded payload exceeds 1 MiB size limit.';
        }
    }

    private function isValidLocale(string $locale): bool
    {
        return (bool) preg_match('/^[a-z]{2}$/', $locale);
    }
}
