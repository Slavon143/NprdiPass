<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreProductAction extends LifecycleAction
{
    public function execute(User $actor, Company $company, Product $product): Product
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
        $this->assertProduct($company, $product);

        return DB::transaction(function () use ($actor, $company, $product): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
            $locked = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ProductStatus::Draft) {
                return $locked;
            }

            if ($locked->status !== ProductStatus::Archived) {
                throw LifecycleOperationException::invalidTransition('Only an archived Product can be restored.');
            }

            $locked->forceFill(['status' => ProductStatus::Draft, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogProductRestored, $actor, $locked, [
                'product_uuid' => $locked->uuid,
                'previous_status' => ProductStatus::Archived->value,
                'new_status' => ProductStatus::Draft->value,
            ]);

            return $locked->refresh();
        });
    }
}
