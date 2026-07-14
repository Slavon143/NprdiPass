<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Exceptions\Catalog\ProductActivationBlocked;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\ProductActivationReadinessService;
use Illuminate\Support\Facades\DB;

class ActivateProductAction extends LifecycleAction
{
    public function __construct(
        CompanyAuthorizer $authorizer,
        AuditLogger $auditLogger,
        private readonly ProductActivationReadinessService $readiness,
    ) {
        parent::__construct($authorizer, $auditLogger);
    }

    public function execute(User $actor, Company $company, Product $product): Product
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogPublish);
        $this->assertProduct($company, $product);

        return DB::transaction(function () use ($actor, $company, $product): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogPublish);
            $locked = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $this->assertProduct($company, $locked);
            $variants = ProductVariant::query()->forCompany($company)
                ->where('product_id', $locked->getKey())->orderBy('id')->lockForUpdate()->get();

            if ($locked->status === ProductStatus::Active) {
                return $locked->load('defaultVariant');
            }

            if ($locked->status !== ProductStatus::Draft) {
                throw LifecycleOperationException::invalidTransition('Archived Products must be restored to draft before activation.');
            }

            $readiness = $this->readiness->evaluate($company, $locked);
            if (! $readiness->ready) {
                throw new ProductActivationBlocked($readiness);
            }

            // R1.1 defines draft Variant activation as part of Product activation.
            ProductVariant::query()->forCompany($company)
                ->where('product_id', $locked->getKey())
                ->where('status', ProductVariantStatus::Draft->value)
                ->update([
                    'status' => ProductVariantStatus::Active->value,
                    'updated_by' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $locked->forceFill([
                'status' => ProductStatus::Active,
                'published_at' => $locked->published_at ?? now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->logTenant($company, AuditEvent::CatalogProductActivated, $actor, $locked, [
                'product_uuid' => $locked->uuid,
                'previous_status' => ProductStatus::Draft->value,
                'new_status' => ProductStatus::Active->value,
                'variant_count' => $variants->count(),
                'required_product_attributes_checked' => $readiness->requiredProductAttributesChecked,
                'required_variant_attributes_checked' => $readiness->requiredVariantAttributesChecked,
                'warning_codes' => $readiness->warningCodes(),
            ]);

            return $locked->refresh()->load('defaultVariant');
        });
    }
}
