<?php

namespace App\Support\Catalog;

use App\Exceptions\Catalog\VariantOperationException;
use App\Models\Catalog\ProductVariant;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class VariantIdentifierNormalizer
{
    public const FIELDS = ['sku', 'gtin', 'mpn'];

    public function __construct(private readonly CatalogIdentifierNormalizer $normalizer) {}

    /** @param array<string, mixed> $data
     * @return array{sku: string|null, sku_normalized: string|null, gtin: string|null, mpn: string|null}
     */
    public function normalize(array $data, ?ProductVariant $fallback = null): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $raw = array_key_exists($field, $data) ? $data[$field] : $fallback?->getAttribute($field);
            if ($raw !== null && ! is_string($raw)) {
                throw VariantOperationException::invalid($field, "The {$field} field must be a string.");
            }
            $values[$field] = $raw === null || trim($raw) === '' ? null : trim($raw);
        }
        if ($values['sku'] !== null && mb_strlen($values['sku']) > 100) {
            throw VariantOperationException::invalid('sku', 'The SKU field may not exceed 100 characters.');
        }
        foreach (self::FIELDS as $field) {
            try {
                $normalized = match ($field) {
                    'sku' => $values['sku'] === null ? null : $this->normalizer->normalizeSku($values['sku']),
                    'gtin' => $this->normalizer->normalizeGtin($values['gtin']),
                    'mpn' => $this->normalizer->normalizeMpn($values['mpn']),
                };
                $values[$field === 'sku' ? 'sku_normalized' : $field] = $normalized === '' ? null : $normalized;
            } catch (InvalidArgumentException $exception) {
                throw VariantOperationException::invalid($field, $exception->getMessage());
            }
        }

        return $values;
    }

    public function conflict(QueryException $exception): ?VariantOperationException
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }
        $message = (string) ($exception->errorInfo[2] ?? '');

        return match (true) {
            str_contains($message, 'variants_company_sku_unique') => VariantOperationException::skuConflict($exception),
            str_contains($message, 'variants_company_gtin_unique') => VariantOperationException::gtinConflict($exception),
            default => null,
        };
    }
}
