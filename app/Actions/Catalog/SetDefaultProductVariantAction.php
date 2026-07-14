<?php

namespace App\Actions\Catalog;

use App\Actions\Catalog\Exceptions\InvalidDefaultVariant;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SetDefaultProductVariantAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Product $product, ProductVariant $variant): Product
    {
        $productKey = $product->getKey();
        $variantKey = $variant->getKey();

        if ($productKey === null || $variantKey === null) {
            throw new InvalidDefaultVariant;
        }

        return DB::transaction(function () use ($actor, $productKey, $variantKey): Product {
            $lockedProduct = Product::query()->lockForUpdate()->find($productKey);

            if ($lockedProduct === null) {
                throw new InvalidDefaultVariant;
            }

            $company = Company::query()->find($lockedProduct->getAttribute('company_id'));

            if ($company === null || $company->status !== CompanyStatus::Active) {
                throw new AuthorizationException;
            }

            $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogUpdate);

            $lockedVariant = ProductVariant::query()->lockForUpdate()->find($variantKey);

            if ($lockedVariant === null
                || $lockedVariant->getAttribute('product_id') !== $lockedProduct->getKey()
                || $lockedVariant->getAttribute('company_id') !== $lockedProduct->getAttribute('company_id')
                || $lockedVariant->getRawOriginal('status') === ProductVariantStatus::Archived->value) {
                throw new InvalidDefaultVariant;
            }

            if ($lockedProduct->getAttribute('default_variant_id') === $lockedVariant->getKey()) {
                return $lockedProduct->load('defaultVariant');
            }

            $oldDefault = ProductVariant::withTrashed()
                ->find($lockedProduct->getAttribute('default_variant_id'));

            $lockedProduct->forceFill([
                'default_variant_id' => $lockedVariant->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::CatalogVariantDefaultChanged,
                $actor,
                $lockedProduct,
                [
                    'product_uuid' => $lockedProduct->getAttribute('uuid'),
                    'old_default_variant_uuid' => $oldDefault?->getAttribute('uuid'),
                    'new_default_variant_uuid' => $lockedVariant->getAttribute('uuid'),
                ],
            );

            return $lockedProduct->refresh()->load('defaultVariant');
        });
    }
}
