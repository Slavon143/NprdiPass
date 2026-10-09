<?php

namespace App\Services\Imports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportService
{
    public const HEADERS = ['name', 'slug', 'short_description', 'description', 'brand', 'manufacturer', 'primary_category', 'sku', 'gtin', 'mpn'];

    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function download(User $actor, Company $company, string $format): StreamedResponse
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogView);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $definitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->ordered()->get();
        $filename = 'products-'.now()->format('Y-m-d-His').'.'.$format;
        $this->auditLogger->logTenant($company, AuditEvent::ExportDownloaded, $actor, 'Product export', ['format' => $format]);

        return $format === 'xlsx' ? $this->xlsx($company, $definitions, $filename) : $this->csv($company, $definitions, $filename);
    }

    /** @param Collection<int, AttributeDefinition> $definitions */
    private function csv(Company $company, Collection $definitions, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($company, $definitions): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->headers($definitions), ',', '"', '');
            $this->query($company)->chunkById(500, function ($products) use ($output, $definitions): void {
                foreach ($products as $product) {
                    fputcsv($output, $this->row($product, $definitions), ',', '"', '');
                }
            });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @param Collection<int, AttributeDefinition> $definitions */
    private function xlsx(Company $company, Collection $definitions, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($company, $definitions): void {
            $path = tempnam(sys_get_temp_dir(), 'np-export-');
            if ($path === false) {
                throw new \RuntimeException('Unable to allocate export archive.');
            }
            try {
                $rows = function () use ($company, $definitions): \Generator {
                    yield $this->headers($definitions);
                    foreach ($this->query($company)->lazyById(500) as $product) {
                        yield $this->row($product, $definitions, xlsx: true);
                    }
                };
                app(StreamingXlsxWriter::class)->write($path, $rows());
                readfile($path);
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function query(Company $company)
    {
        return Product::query()->forCompany($company)->with([
            'primaryCategory',
            'defaultVariant' => fn ($query) => $query->where('company_id', $company->getKey()),
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ])->orderBy('id');
    }

    /** @param Collection<int, AttributeDefinition> $definitions @return list<string> */
    private function headers(Collection $definitions): array
    {
        return [...self::HEADERS, ...$definitions->map(fn (AttributeDefinition $definition): string => 'attribute:'.$definition->code)->all()];
    }

    /** @param Collection<int, AttributeDefinition> $definitions @return list<string> */
    private function row(Product $product, Collection $definitions, bool $xlsx = false): array
    {
        $row = array_map(function (string $field) use ($product, $xlsx): string {
            $identifier = in_array($field, ['sku', 'gtin', 'mpn'], true);
            $value = $field === 'primary_category' ? $product->primaryCategory?->slug
                : ($identifier
                    ? ((int) $product->defaultVariant?->product_id === (int) $product->getKey() ? $product->defaultVariant?->getAttribute($field) : null)
                    : $product->getAttribute($field));

            // The XLSX writer uses explicit string cells; CSV keeps its formula guard.
            return $xlsx && $identifier ? (string) ($value ?? '') : $this->safeCell($value);
        }, self::HEADERS);
        $values = $product->attributeValues->keyBy('attribute_definition_id');
        foreach ($definitions as $definition) {
            $value = $values->get($definition->getKey());
            $row[] = $value instanceof ProductAttributeValue ? $this->safeCell($this->attributeValue($definition, $value)) : '';
        }

        return $row;
    }

    private function attributeValue(AttributeDefinition $definition, ProductAttributeValue $value): mixed
    {
        return match ($definition->type) {
            AttributeDataType::Text => $value->value_text,
            AttributeDataType::Integer => $value->value_integer,
            AttributeDataType::Decimal => rtrim(rtrim((string) $value->value_decimal, '0'), '.'),
            AttributeDataType::Boolean => $value->value_boolean ? '1' : '0',
            AttributeDataType::Date => $value->value_date?->format('Y-m-d'),
            AttributeDataType::Select => $value->selectedOption?->code,
            AttributeDataType::Multiselect => $value->selectedOptions->pluck('code')->implode('|'),
        };
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
