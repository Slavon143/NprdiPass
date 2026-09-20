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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportService
{
    public const HEADERS = ['name', 'slug', 'short_description', 'description', 'brand', 'manufacturer', 'primary_category'];

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
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($this->headers($definitions) as $column => $header) {
                $sheet->setCellValueExplicit([$column + 1, 1], $header, DataType::TYPE_STRING);
            }
            $row = 2;
            $this->query($company)->chunkById(500, function ($products) use ($sheet, &$row, $definitions): void {
                foreach ($products as $product) {
                    foreach ($this->row($product, $definitions) as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $row], $value, DataType::TYPE_STRING);
                    }
                    $row++;
                }
            });
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function query(Company $company)
    {
        return Product::query()->forCompany($company)->with([
            'primaryCategory',
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
    private function row(Product $product, Collection $definitions): array
    {
        $row = array_map(fn (string $field): string => $field === 'primary_category'
            ? $this->safeCell($product->primaryCategory?->slug)
            : $this->safeCell($product->getAttribute($field)), self::HEADERS);
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
