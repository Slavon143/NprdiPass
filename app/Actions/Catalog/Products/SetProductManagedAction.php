<?php

namespace App\Actions\Catalog\Products;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetProductManagedAction extends ProductAction
{
    public function execute(User $actor, Company $company, Product $product, bool $managed): Product
    {
        return DB::transaction(function () use ($actor, $company, $product, $managed): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
            $this->capacity->lockCapacity($company);
            $current = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($current->managed === $managed) {
                return $current;
            }
            if ($managed && in_array($current->status, [ProductStatus::Draft, ProductStatus::Active], true)) {
                $this->capacity->assertSlotAvailable($company);
            }
            $previous = $current->managed;
            $current->forceFill(['managed' => $managed, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogProductManagedChanged, $actor, $current, [
                'product_uuid' => $current->uuid, 'previous_managed' => $previous, 'managed' => $managed,
            ]);

            return $current->refresh();
        }, 5);
    }
}
