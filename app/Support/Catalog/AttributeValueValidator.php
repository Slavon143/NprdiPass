<?php

namespace App\Support\Catalog;

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use Carbon\CarbonImmutable;

class AttributeValueValidator
{
    public const MAX_MULTISELECT_OPTIONS = 200;

    public function normalize(
        Company $company,
        AttributeDefinition $definition,
        AttributeScope $targetScope,
        mixed $raw,
    ): NormalizedAttributeValue {
        $this->assertDefinition($company, $definition, $targetScope);

        return match ($definition->type) {
            AttributeDataType::Text => $this->text($definition, $raw),
            AttributeDataType::Integer => $this->integer($definition, $raw),
            AttributeDataType::Decimal => $this->decimal($definition, $raw),
            AttributeDataType::Boolean => $this->boolean($definition, $raw),
            AttributeDataType::Date => $this->date($definition, $raw),
            AttributeDataType::Select => $this->select($company, $definition, $raw),
            AttributeDataType::Multiselect => $this->multiselect($company, $definition, $raw),
        };
    }

    /** @return array<string, int|float|string> */
    public function normalizeRules(AttributeDataType $type, mixed $rules): array
    {
        if ($rules === null || $rules === '' || $rules === []) {
            return [];
        }

        if (! is_array($rules)) {
            throw AttributeOperationException::invalid('validation_rules', 'Validation rules must be a structured object.');
        }

        $allowed = match ($type) {
            AttributeDataType::Text => ['min_length', 'max_length'],
            AttributeDataType::Integer, AttributeDataType::Decimal => ['min', 'max'],
            AttributeDataType::Date => ['min_date', 'max_date'],
            AttributeDataType::Multiselect => ['min_selections', 'max_selections'],
            AttributeDataType::Boolean, AttributeDataType::Select => [],
        };

        if (array_diff(array_keys($rules), $allowed) !== []) {
            throw AttributeOperationException::invalid('validation_rules', 'Validation rules contain fields that are not allowed for this data type.');
        }

        $normalized = [];

        foreach ($allowed as $key) {
            $value = $rules[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($key, ['min_length', 'max_length', 'min_selections', 'max_selections'], true)) {
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw AttributeOperationException::invalid("validation_rules.{$key}", 'This validation rule must be an integer.');
                }

                $integer = (int) $value;
                $maximum = str_contains($key, 'length') ? 1000 : self::MAX_MULTISELECT_OPTIONS;

                if ($integer < 0 || $integer > $maximum) {
                    throw AttributeOperationException::invalid("validation_rules.{$key}", "This validation rule must be between 0 and {$maximum}.");
                }

                $normalized[$key] = $integer;
            } elseif (in_array($key, ['min', 'max'], true)) {
                if (! is_int($value) && ! is_float($value) && (! is_string($value) || preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value) !== 1)) {
                    throw AttributeOperationException::invalid("validation_rules.{$key}", 'This validation rule must be a decimal number with at most four decimal places.');
                }

                $normalized[$key] = (string) $value;
            } else {
                $date = $this->parseDate($value, "validation_rules.{$key}");
                $normalized[$key] = $date;
            }
        }

        $pairs = [
            ['min_length', 'max_length'],
            ['min_selections', 'max_selections'],
            ['min', 'max'],
            ['min_date', 'max_date'],
        ];

        foreach ($pairs as [$minimum, $maximum]) {
            if (isset($normalized[$minimum], $normalized[$maximum]) && $this->compare((string) $normalized[$minimum], (string) $normalized[$maximum], $type) > 0) {
                throw AttributeOperationException::invalid('validation_rules', 'The minimum validation rule may not exceed the maximum.');
            }
        }

        return $normalized;
    }

    private function assertDefinition(Company $company, AttributeDefinition $definition, AttributeScope $targetScope): void
    {
        if ((int) $definition->company_id !== (int) $company->getKey()) {
            throw AttributeOperationException::tenantMismatch();
        }

        if ($definition->status !== AttributeDefinitionStatus::Active) {
            throw AttributeOperationException::invalid('attributes', 'Archived attributes cannot receive new values.');
        }

        $allowed = $targetScope === AttributeScope::Product
            ? [AttributeScope::Product, AttributeScope::Both]
            : [AttributeScope::Variant, AttributeScope::Both];

        if (! in_array($definition->scope, $allowed, true)) {
            throw AttributeOperationException::invalid('attributes', 'This attribute is not available for the selected entity type.');
        }
    }

    private function text(AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $this->clear($definition);
        }

        if (! is_string($raw)) {
            throw AttributeOperationException::invalid($this->field($definition), 'The value must be text.');
        }

        $value = trim($raw);
        $length = mb_strlen($value);
        $rules = $definition->validation_rules ?? [];

        if ($length > 1000 || $length < (int) ($rules['min_length'] ?? 0) || $length > (int) ($rules['max_length'] ?? 1000)) {
            throw AttributeOperationException::invalid($this->field($definition), 'The text value does not satisfy its length rules.');
        }

        return new NormalizedAttributeValue($definition, false, $this->columns(valueText: $value));
    }

    private function integer(AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || $raw === '') {
            return $this->clear($definition);
        }

        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            $raw = filter_var($raw, FILTER_VALIDATE_INT);
        }

        if (! is_int($raw)) {
            throw AttributeOperationException::invalid($this->field($definition), 'The value must be an integer.');
        }

        $this->assertNumericRules($definition, (string) $raw);

        return new NormalizedAttributeValue($definition, false, $this->columns(valueInteger: $raw));
    }

    private function decimal(AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || $raw === '') {
            return $this->clear($definition);
        }

        $value = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : null);

        if ($value === null || preg_match('/^-?\d{1,16}(?:\.\d{1,4})?$/', $value) !== 1) {
            throw AttributeOperationException::invalid($this->field($definition), 'The value must fit DECIMAL(20,4).');
        }

        $this->assertNumericRules($definition, $value);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $value = $whole.'.'.str_pad($fraction, 4, '0');

        return new NormalizedAttributeValue($definition, false, $this->columns(valueDecimal: $value));
    }

    private function boolean(AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || $raw === '') {
            return $this->clear($definition);
        }

        $value = match (true) {
            $raw === true, $raw === 1, $raw === '1' => true,
            $raw === false, $raw === 0, $raw === '0' => false,
            default => null,
        };

        if ($value === null) {
            throw AttributeOperationException::invalid($this->field($definition), 'Choose Yes, No, or Not set.');
        }

        return new NormalizedAttributeValue($definition, false, $this->columns(valueBoolean: $value));
    }

    private function date(AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || $raw === '') {
            return $this->clear($definition);
        }

        $value = $this->parseDate($raw, $this->field($definition));
        $rules = $definition->validation_rules ?? [];

        if ((isset($rules['min_date']) && $value < $rules['min_date']) || (isset($rules['max_date']) && $value > $rules['max_date'])) {
            throw AttributeOperationException::invalid($this->field($definition), 'The date is outside the allowed range.');
        }

        return new NormalizedAttributeValue($definition, false, $this->columns(valueDate: $value));
    }

    private function select(Company $company, AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || $raw === '') {
            return $this->clear($definition);
        }

        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw AttributeOperationException::invalid($this->field($definition), 'Choose a valid option.');
        }

        $id = (int) $raw;
        $this->activeOptions($company, $definition, [$id]);

        return new NormalizedAttributeValue($definition, false, $this->columns(valueOptionId: $id));
    }

    private function multiselect(Company $company, AttributeDefinition $definition, mixed $raw): NormalizedAttributeValue
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return $this->clear($definition);
        }

        if (! is_array($raw)) {
            throw AttributeOperationException::invalid($this->field($definition), 'Choose one or more valid options.');
        }

        $ids = [];

        foreach ($raw as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw AttributeOperationException::invalid($this->field($definition), 'Choose only valid options.');
            }

            $ids[] = (int) $id;
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw AttributeOperationException::invalid($this->field($definition), 'Duplicate options are not allowed.');
        }

        sort($ids);
        $rules = $definition->validation_rules ?? [];
        $count = count($ids);

        if ($count > self::MAX_MULTISELECT_OPTIONS
            || $count < (int) ($rules['min_selections'] ?? 0)
            || $count > (int) ($rules['max_selections'] ?? self::MAX_MULTISELECT_OPTIONS)) {
            throw AttributeOperationException::invalid($this->field($definition), 'The selected options do not satisfy the selection limits.');
        }

        $this->activeOptions($company, $definition, $ids);

        return new NormalizedAttributeValue($definition, false, $this->columns(), $ids);
    }

    /** @param list<int> $ids */
    private function activeOptions(Company $company, AttributeDefinition $definition, array $ids): void
    {
        $count = AttributeOption::query()
            ->forCompany($company)
            ->where('attribute_definition_id', $definition->getKey())
            ->where('status', AttributeOptionStatus::Active->value)
            ->whereKey($ids)
            ->count();

        if ($count !== count($ids)) {
            throw AttributeOperationException::optionMismatch();
        }
    }

    private function assertNumericRules(AttributeDefinition $definition, string $value): void
    {
        $rules = $definition->validation_rules ?? [];

        if ((isset($rules['min']) && $this->decimalCompare($value, (string) $rules['min']) < 0)
            || (isset($rules['max']) && $this->decimalCompare($value, (string) $rules['max']) > 0)) {
            throw AttributeOperationException::invalid($this->field($definition), 'The value is outside the allowed range.');
        }
    }

    private function parseDate(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw AttributeOperationException::invalid($field, 'The date must use YYYY-MM-DD.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw AttributeOperationException::invalid($field, 'The date must be a real calendar date.');
        }

        return $value;
    }

    private function compare(string $minimum, string $maximum, AttributeDataType $type): int
    {
        return match ($type) {
            AttributeDataType::Integer, AttributeDataType::Decimal => $this->decimalCompare($minimum, $maximum),
            default => $minimum <=> $maximum,
        };
    }

    private function decimalCompare(string $left, string $right): int
    {
        $normalize = static function (string $value): array {
            $negative = str_starts_with($value, '-');
            $value = ltrim($value, '+-');
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

            return [$negative, ltrim($whole, '0') ?: '0', rtrim(str_pad($fraction, 4, '0'), '0')];
        };

        [$leftNegative, $leftWhole, $leftFraction] = $normalize($left);
        [$rightNegative, $rightWhole, $rightFraction] = $normalize($right);

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = strlen($leftWhole) <=> strlen($rightWhole);
        $comparison = $comparison !== 0 ? $comparison : strcmp($leftWhole, $rightWhole);
        $comparison = $comparison !== 0 ? $comparison : strcmp(str_pad($leftFraction, 4, '0'), str_pad($rightFraction, 4, '0'));

        return $leftNegative ? -$comparison : $comparison;
    }

    private function field(AttributeDefinition $definition): string
    {
        return 'attributes.'.$definition->uuid;
    }

    private function clear(AttributeDefinition $definition): NormalizedAttributeValue
    {
        return new NormalizedAttributeValue($definition, true, $this->columns());
    }

    /** @return array{value_text: string|null, value_integer: int|null, value_decimal: string|null, value_boolean: bool|null, value_date: string|null, value_option_id: int|null} */
    private function columns(
        ?string $valueText = null,
        ?int $valueInteger = null,
        ?string $valueDecimal = null,
        ?bool $valueBoolean = null,
        ?string $valueDate = null,
        ?int $valueOptionId = null,
    ): array {
        return [
            'value_text' => $valueText,
            'value_integer' => $valueInteger,
            'value_decimal' => $valueDecimal,
            'value_boolean' => $valueBoolean,
            'value_date' => $valueDate,
            'value_option_id' => $valueOptionId,
        ];
    }
}
