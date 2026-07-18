<?php

namespace App\Actions\Catalog\Attributes;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkDeleteAttributesAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{deleted: list<string>, blocked: list<array{uuid: string, name: string, reason: string}>}
     */
    public function execute(User $actor, Company $company, array $uuids): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);

        return DB::transaction(function () use ($actor, $company, $uuids): array {
            $all = AttributeDefinition::query()
                ->forCompany($company)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $ids = $all->pluck('id')->all();

            $productCounts = DB::table('product_attribute_values')
                ->join('products', function ($join): void {
                    $join->on('products.company_id', '=', 'product_attribute_values.company_id')
                        ->on('products.id', '=', 'product_attribute_values.product_id');
                })
                ->where('product_attribute_values.company_id', $company->getKey())
                ->whereIn('product_attribute_values.attribute_definition_id', $ids)
                ->where('products.status', '!=', ProductStatus::Archived->value)
                ->select('product_attribute_values.attribute_definition_id', DB::raw('count(distinct products.id) as aggregate'))
                ->groupBy('product_attribute_values.attribute_definition_id')
                ->get()
                ->pluck('aggregate', 'attribute_definition_id');

            $variantCounts = DB::table('variant_attribute_values')
                ->join('product_variants as variants', function ($join): void {
                    $join->on('variants.company_id', '=', 'variant_attribute_values.company_id')
                        ->on('variants.id', '=', 'variant_attribute_values.product_variant_id');
                })
                ->join('products', function ($join): void {
                    $join->on('products.company_id', '=', 'variants.company_id')
                        ->on('products.id', '=', 'variants.product_id');
                })
                ->where('variant_attribute_values.company_id', $company->getKey())
                ->whereIn('variant_attribute_values.attribute_definition_id', $ids)
                ->where('variants.status', '!=', ProductVariantStatus::Archived->value)
                ->where('products.status', '!=', ProductStatus::Archived->value)
                ->select('variant_attribute_values.attribute_definition_id', DB::raw('count(distinct variants.id) as aggregate'))
                ->groupBy('variant_attribute_values.attribute_definition_id')
                ->get()
                ->pluck('aggregate', 'attribute_definition_id');

            $deleted = [];
            $blocked = [];

            foreach ($uuids as $uuid) {
                $definition = $all->get($uuid);

                if (! $definition instanceof AttributeDefinition) {
                    continue;
                }

                $productCount = $productCounts->get($definition->getKey(), 0);
                $variantCount = $variantCounts->get($definition->getKey(), 0);

                if ($productCount > 0 || $variantCount > 0) {
                    $parts = [];

                    if ($productCount > 0) {
                        $parts[] = trans_choice(':count active product|:count active products', $productCount, ['count' => $productCount]);
                    }

                    if ($variantCount > 0) {
                        $parts[] = trans_choice(':count active variant|:count active variants', $variantCount, ['count' => $variantCount]);
                    }

                    $blocked[] = [
                        'uuid' => $definition->uuid,
                        'name' => $definition->name,
                        'reason' => implode(', ', $parts),
                    ];

                    continue;
                }
            }

            if ($blocked !== []) {
                return ['deleted' => [], 'blocked' => $blocked];
            }

            foreach ($uuids as $uuid) {
                $definition = $all->get($uuid);

                if (! $definition instanceof AttributeDefinition) {
                    continue;
                }

                $attrUuid = $definition->uuid;
                $attrName = $definition->name;
                $attrCode = $definition->code;

                $this->deleteAttributeValues($company, $definition);
                $definition->forceFill(['updated_by' => $actor->getKey()])->save();
                $definition->delete();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeArchived, $actor, null, [
                    'attribute_uuid' => $attrUuid,
                    'attribute_name' => $attrName,
                    'attribute_code' => $attrCode,
                    'action' => 'bulk_deleted',
                ]);

                $deleted[] = $attrUuid;
            }

            return ['deleted' => $deleted, 'blocked' => $blocked];
        });
    }

    private function deleteAttributeValues(Company $company, AttributeDefinition $definition): void
    {
        DB::table('product_attribute_value_options')
            ->where('company_id', $company->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->delete();

        DB::table('variant_attribute_value_options')
            ->where('company_id', $company->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->delete();

        DB::table('product_attribute_values')
            ->where('company_id', $company->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->delete();

        DB::table('variant_attribute_values')
            ->where('company_id', $company->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->delete();
    }
}
