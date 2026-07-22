<?php

namespace App\Services\Passports;

use App\Data\Passports\DppFieldDefinition;
use App\Data\Passports\DppSectionDefinition;
use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;

class DppPayloadNormalizer
{
    private DppSchemaRegistry $registry;

    public function __construct(DppSchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Normalize the full payload for schema_version = 1.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $sections = $this->registry->sections();
        $canonicalOrder = $this->registry->sectionKeysInOrder();

        $enabledSections = $this->normalizeEnabledSections(
            $payload['enabled_sections'] ?? [],
            $canonicalOrder,
            $sections,
        );

        $translations = $this->normalizeTranslations(
            $payload['translations'] ?? [],
            $enabledSections,
            $sections,
        );

        $data = $this->normalizeData(
            $payload['data'] ?? [],
            $enabledSections,
            $sections,
        );

        $documentReferences = $this->normalizeDocumentReferences(
            $payload['document_references'] ?? [],
        );

        $result = [
            'enabled_sections' => $enabledSections,
            'data' => $data,
            'translations' => $translations,
            'document_references' => $documentReferences,
        ];

        return $result;
    }

    /**
     * @param  array<int, mixed>  $rawSections
     * @param  string[]  $canonicalOrder
     * @param  array<string, DppSectionDefinition>  $sections
     * @return string[]
     */
    private function normalizeEnabledSections(array $rawSections, array $canonicalOrder, array $sections): array
    {
        $validSections = array_keys($sections);
        $enabled = [];

        foreach ($rawSections as $section) {
            if (is_string($section) && in_array($section, $validSections, true)) {
                $enabled[] = $section;
            }
        }

        $enabled = array_unique($enabled);

        usort($enabled, function (string $a, string $b) use ($canonicalOrder): int {
            $posA = array_search($a, $canonicalOrder, true);
            $posB = array_search($b, $canonicalOrder, true);

            return $posA <=> $posB;
        });

        return $enabled;
    }

    /**
     * @param  array<mixed, mixed>  $translations
     * @param  string[]  $enabledSections
     * @param  array<string, DppSectionDefinition>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function normalizeTranslations(array $translations, array $enabledSections, array $sections): array
    {
        $result = [];

        foreach ($translations as $locale => $localeData) {
            if (! is_string($locale) || ! $this->isValidLocale($locale)) {
                continue;
            }

            if (! is_array($localeData)) {
                continue;
            }

            /** @var array<string, mixed> $localeData */
            $sectionData = $this->normalizeSectionTranslations($localeData, $enabledSections, $sections);

            if ($sectionData !== []) {
                $result[$locale] = $sectionData;
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed, mixed>  $localeData
     * @param  string[]  $enabledSections
     * @param  array<string, DppSectionDefinition>  $sections
     * @return array<string, mixed>
     */
    private function normalizeSectionTranslations(array $localeData, array $enabledSections, array $sections): array
    {
        $result = [];

        foreach ($localeData as $sectionKey => $fields) {
            if (! is_string($sectionKey) || ! isset($sections[$sectionKey])) {
                continue;
            }

            $sectionDef = $sections[$sectionKey];

            if (! $sectionDef->translatable) {
                continue;
            }

            if (! in_array($sectionKey, $enabledSections, true)) {
                continue;
            }

            if (! is_array($fields)) {
                continue;
            }

            /** @var array<string, mixed> $fields */
            $normalizedFields = $this->normalizeTranslatableFields($fields, $sectionDef->fields);

            if ($normalizedFields !== []) {
                $result[$sectionKey] = $normalizedFields;
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed, mixed>  $rawData
     * @param  string[]  $enabledSections
     * @param  array<string, DppSectionDefinition>  $sections
     * @return array<string, mixed>
     */
    private function normalizeData(array $rawData, array $enabledSections, array $sections): array
    {
        $result = [];

        foreach ($rawData as $sectionKey => $fields) {
            if (! is_string($sectionKey) || ! isset($sections[$sectionKey])) {
                continue;
            }

            $sectionDef = $sections[$sectionKey];

            if ($sectionDef->translatable) {
                $nonTranslatableFields = $this->normalizeNonTranslatableFields($fields, $sectionDef->fields);

                if ($nonTranslatableFields !== []) {
                    $result[$sectionKey] = $nonTranslatableFields;
                }

                continue;
            }

            if (! in_array($sectionKey, $enabledSections, true)) {
                continue;
            }

            if (! is_array($fields)) {
                continue;
            }

            /** @var array<string, mixed> $fields */
            $normalizedFields = $this->normalizeNonTranslatableFields($fields, $sectionDef->fields);

            if ($normalizedFields !== []) {
                $result[$sectionKey] = $normalizedFields;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  DppFieldDefinition[]  $fieldDefs
     * @return array<string, mixed>
     */
    private function normalizeNonTranslatableFields(array $fields, array $fieldDefs): array
    {
        $result = [];
        $defMap = [];

        foreach ($fieldDefs as $def) {
            $defMap[$def->key] = $def;
        }

        foreach ($fields as $fieldKey => $value) {
            $def = $defMap[$fieldKey] ?? null;

            if ($def === null || $def->translatable) {
                continue;
            }

            $normalized = $this->normalizeValue($value, $def);

            if ($normalized !== null) {
                $result[$fieldKey] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  DppFieldDefinition[]  $fieldDefs
     * @return array<string, mixed>
     */
    private function normalizeTranslatableFields(array $fields, array $fieldDefs): array
    {
        $result = [];
        $defMap = [];

        foreach ($fieldDefs as $def) {
            $defMap[$def->key] = $def;
        }

        foreach ($fields as $fieldKey => $value) {
            $def = $defMap[$fieldKey] ?? null;

            if ($def === null || ! $def->translatable) {
                continue;
            }

            $normalized = $this->normalizeValue($value, $def);

            if ($normalized !== null) {
                $result[$fieldKey] = $normalized;
            }
        }

        return $result;
    }

    private function normalizeValue(mixed $value, DppFieldDefinition $def): mixed
    {
        $type = $def->type;

        return match ($type) {
            DppFieldType::ShortText => $this->normalizeShortText($value, $def),
            DppFieldType::LongText => $this->normalizeLongText($value, $def),
            DppFieldType::Boolean => $this->normalizeBoolean($value, $def),
            DppFieldType::Integer => $this->normalizeInteger($value, $def),
            DppFieldType::Decimal => $this->normalizeDecimal($value, $def),
            DppFieldType::Date => $this->normalizeDate($value, $def),
            DppFieldType::Email => $this->normalizeEmail($value, $def),
            DppFieldType::Url => $this->normalizeUrl($value, $def),
            DppFieldType::CountryCode => $this->normalizeCountryCode($value, $def),
            DppFieldType::StringList => $this->normalizeStringList($value, $def),
            DppFieldType::MaterialList => $this->normalizeMaterialList($value, $def),
            DppFieldType::JsonList => $this->normalizeJsonList($value, $def),
        };
    }

    private function normalizeShortText(mixed $value, DppFieldDefinition $def): ?string
    {
        if ($value === null || $value === '') {
            return $def->nullable ? null : '';
        }

        $text = trim((string) $value);

        return $text === '' ? ($def->nullable ? null : '') : $text;
    }

    private function normalizeLongText(mixed $value, DppFieldDefinition $def): ?string
    {
        return $this->normalizeShortText($value, $def);
    }

    private function normalizeBoolean(mixed $value, DppFieldDefinition $def): ?bool
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function normalizeInteger(mixed $value, DppFieldDefinition $def): ?int
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeDecimal(mixed $value, DppFieldDefinition $def): ?string
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $decimal = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $decimal)) {
            return null;
        }

        return $this->canonicalDecimal($decimal);
    }

    private function normalizeDate(mixed $value, DppFieldDefinition $def): ?string
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        $str = trim((string) ($value ?? ''));

        if ($str === '') {
            return $def->nullable ? null : null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            return $str;
        }

        return null;
    }

    private function normalizeEmail(mixed $value, DppFieldDefinition $def): ?string
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        $str = trim((string) ($value ?? ''));

        if ($str === '') {
            return $def->nullable ? null : null;
        }

        return mb_strtolower($str);
    }

    private function normalizeUrl(mixed $value, DppFieldDefinition $def): ?string
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        $str = trim((string) ($value ?? ''));

        if ($str === '') {
            return $def->nullable ? null : null;
        }

        $parsed = parse_url($str);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $scheme = mb_strtolower($parsed['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($this->isUnsafeHost((string) $parsed['host'])) {
            return null;
        }

        unset($parsed['user'], $parsed['pass']);

        return $this->buildUrl($parsed);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function buildUrl(array $parts): string
    {
        $url = ($parts['scheme'] ?? '').'://';
        $url .= $parts['host'] ?? '';

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '/';

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    private function normalizeCountryCode(mixed $value, DppFieldDefinition $def): ?string
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        $str = trim((string) ($value ?? ''));

        if ($str === '') {
            return $def->nullable ? null : null;
        }

        return mb_strtoupper($str);
    }

    /** @return string[]|null */
    private function normalizeStringList(mixed $value, DppFieldDefinition $def): ?array
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $items = [];
        $seen = [];

        foreach ($value as $item) {
            $str = trim((string) $item);

            if ($str === '') {
                continue;
            }

            $normalized = mb_strtolower($str);

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $items[] = $str;
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function normalizeMaterialList(mixed $value, DppFieldDefinition $def): ?array
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $items = [];

        foreach (array_values($value) as $item) {
            if (! is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $name = $this->normalizeShortText($item['name'] ?? null, new DppFieldDefinition('name', DppFieldType::ShortText, false, false, DppSectionKey::MaterialsAndComposition));

            if ($name === null || $name === '') {
                continue;
            }

            $material = [
                'code' => $this->normalizeShortText($item['code'] ?? null, new DppFieldDefinition('code', DppFieldType::ShortText, false, true, DppSectionKey::MaterialsAndComposition, maxLength: 120)),
                'type' => $this->normalizeShortText($item['type'] ?? null, new DppFieldDefinition('type', DppFieldType::ShortText, false, true, DppSectionKey::MaterialsAndComposition, maxLength: 120)),
                'name' => $name,
                'display_name' => $this->normalizeShortText($item['display_name'] ?? null, new DppFieldDefinition('display_name', DppFieldType::ShortText, true, true, DppSectionKey::MaterialsAndComposition, maxLength: 500)),
                'percentage' => $this->normalizeDecimal($item['percentage'] ?? null, new DppFieldDefinition('percentage', DppFieldType::Decimal, false, true, DppSectionKey::MaterialsAndComposition, min: 0, max: 100)),
                'basis' => $this->normalizeShortText($item['basis'] ?? null, new DppFieldDefinition('basis', DppFieldType::ShortText, false, true, DppSectionKey::MaterialsAndComposition, maxLength: 80)),
                'recycled_content_percentage' => $this->normalizeDecimal($item['recycled_content_percentage'] ?? null, new DppFieldDefinition('recycled_content_percentage', DppFieldType::Decimal, false, true, DppSectionKey::MaterialsAndComposition, min: 0, max: 100)),
                'renewable_content_percentage' => $this->normalizeDecimal($item['renewable_content_percentage'] ?? null, new DppFieldDefinition('renewable_content_percentage', DppFieldType::Decimal, false, true, DppSectionKey::MaterialsAndComposition, min: 0, max: 100)),
                'hazardous' => $this->normalizeBoolean($item['hazardous'] ?? null, new DppFieldDefinition('hazardous', DppFieldType::Boolean, false, false, DppSectionKey::MaterialsAndComposition)),
                'country_of_origin' => $this->normalizeCountryCode($item['country_of_origin'] ?? null, new DppFieldDefinition('country_of_origin', DppFieldType::CountryCode, false, true, DppSectionKey::MaterialsAndComposition)),
                'source' => $this->normalizeShortText($item['source'] ?? null, new DppFieldDefinition('source', DppFieldType::ShortText, false, true, DppSectionKey::MaterialsAndComposition, maxLength: 500)),
                'notes' => $this->normalizeLongText($item['notes'] ?? null, new DppFieldDefinition('notes', DppFieldType::LongText, true, true, DppSectionKey::MaterialsAndComposition, maxLength: 2000)),
                'sort_order' => $this->normalizeInteger($item['sort_order'] ?? null, new DppFieldDefinition('sort_order', DppFieldType::Integer, false, true, DppSectionKey::MaterialsAndComposition, min: 0)),
            ];

            $material = array_filter($material, fn ($v) => $v !== null)
                + ['hazardous' => false];

            $items[] = $material;
        }

        usort($items, function (array $a, array $b): int {
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));

            return $order !== 0 ? $order : strcmp((string) $a['name'], (string) $b['name']);
        });

        return $items;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function normalizeJsonList(mixed $value, DppFieldDefinition $def): ?array
    {
        if ($value === null && $def->nullable) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (! is_array($decoded)) {
                return null;
            }

            $value = $decoded;
        }

        if (! is_array($value)) {
            return null;
        }

        $result = [];
        $stringFields = $def->bounds['string_fields'] ?? [];

        foreach (array_values($value) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = [];
            foreach ($item as $key => $raw) {
                if (! is_string($key)) {
                    continue;
                }

                if (is_string($raw)) {
                    $raw = trim($raw);
                }

                if ($raw === null || $raw === '') {
                    continue;
                }

                if (str_ends_with($key, '_url') || $key === 'url' || $key === 'website') {
                    $url = $this->normalizeUrl($raw, new DppFieldDefinition($key, DppFieldType::Url, false, true, $def->section));
                    if ($url !== null) {
                        $normalized[$key] = $url;
                    }

                    continue;
                }

                $normalized[$key] = in_array($key, $stringFields, true) ? (string) $raw : $raw;
            }

            if ($normalized !== []) {
                $normalized['sort_order'] = isset($normalized['sort_order']) ? (int) $normalized['sort_order'] : $index;
                ksort($normalized);
                $result[] = $normalized;
            }
        }

        usort($result, function (array $a, array $b): int {
            $order = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);

            return $order !== 0 ? $order : json_encode($a) <=> json_encode($b);
        });

        return $result;
    }

    /**
     * @param  array<int, mixed>  $refs
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDocumentReferences(array $refs): array
    {
        $result = [];

        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            /** @var array<string, mixed> $ref */
            $uuid = isset($ref['document_uuid']) ? trim((string) $ref['document_uuid']) : '';

            if ($uuid === '') {
                continue;
            }

            $result[] = [
                'document_uuid' => $uuid,
                'document_version_uuid' => isset($ref['document_version_uuid']) ? trim((string) $ref['document_version_uuid']) : null,
                'role' => isset($ref['role']) ? trim((string) $ref['role']) : 'other',
                'display_order' => isset($ref['display_order']) ? max(0, (int) $ref['display_order']) : 0,
            ];
        }

        usort($result, function (array $a, array $b): int {
            $order = $a['display_order'] <=> $b['display_order'];

            return $order !== 0 ? $order : $a['document_uuid'] <=> $b['document_uuid'];
        });

        return $result;
    }

    private function isValidLocale(string $locale): bool
    {
        return (bool) preg_match('/^[a-z]{2}$/', $locale);
    }

    private function canonicalDecimal(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        $canonical = $fraction === '' ? $integer : "{$integer}.{$fraction}";

        return $negative && $canonical !== '0' ? "-{$canonical}" : $canonical;
    }

    private function isUnsafeHost(string $host): bool
    {
        $host = mb_strtolower(trim($host, "[] \t\n\r\0\x0B"));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return false;
    }
}
