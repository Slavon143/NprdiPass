<?php

namespace App\Actions\Catalog\Attributes;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAttributeAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, AttributeDefinition $definition): AttributeDefinition
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);

        return DB::transaction(function () use ($actor, $company, $definition): AttributeDefinition {
            $locked = AttributeDefinition::query()
                ->forCompany($company)
                ->whereKey($definition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoValues($company, $locked);
            $this->deleteArchivedValues($company, $locked);

            $locked->load('options');
            $uuid = $locked->uuid;
            $name = $locked->name;
            $code = $locked->code;

            $locked->forceFill(['updated_by' => $actor->getKey()])->save();
            $locked->delete();

            $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeArchived, $actor, null, [
                'attribute_uuid' => $uuid,
                'attribute_name' => $name,
                'attribute_code' => $code,
                'action' => 'deleted',
            ]);

            return $locked;
        });
    }

    private function assertNoValues(Company $company, AttributeDefinition $definition): void
    {
        $productCount = DB::table('product_attribute_values as values')
            ->join('products', function ($join): void {
                $join->on('products.company_id', '=', 'values.company_id')
                    ->on('products.id', '=', 'values.product_id');
            })
            ->where('values.company_id', $company->getKey())
            ->where('values.attribute_definition_id', $definition->getKey())
            ->where('products.status', '!=', ProductStatus::Archived->value)
            ->distinct()
            ->count('products.id');

        $variantCount = DB::table('variant_attribute_values as values')
            ->join('product_variants as variants', function ($join): void {
                $join->on('variants.company_id', '=', 'values.company_id')
                    ->on('variants.id', '=', 'values.product_variant_id');
            })
            ->join('products', function ($join): void {
                $join->on('products.company_id', '=', 'variants.company_id')
                    ->on('products.id', '=', 'variants.product_id');
            })
            ->where('values.company_id', $company->getKey())
            ->where('values.attribute_definition_id', $definition->getKey())
            ->where('variants.status', '!=', ProductVariantStatus::Archived->value)
            ->where('products.status', '!=', ProductStatus::Archived->value)
            ->distinct()
            ->count('variants.id');

        if ($productCount > 0 || $variantCount > 0) {
            $parts = [];

            if ($productCount > 0) {
                $parts[] = trans_choice(':count active product|:count active products', $productCount, ['count' => $productCount]);
            }

            if ($variantCount > 0) {
                $parts[] = trans_choice(':count active variant|:count active variants', $variantCount, ['count' => $variantCount]);
            }

            throw AttributeOperationException::blocked(
                __('Attribute «:name» is used in ', ['name' => $definition->name])
                .implode(' and ', $parts).'.'
            );
        }
    }

    private function deleteArchivedValues(Company $company, AttributeDefinition $definition): void
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
