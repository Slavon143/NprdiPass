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

class ReturnProductToDraftAction extends LifecycleAction
{
    public function execute(User $actor, Company $company, Product $product): Product
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogPublish);
        $this->assertProduct($company, $product);

        return DB::transaction(function () use ($actor, $company, $product): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogPublish);
            $locked = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ProductStatus::Draft) {
                return $locked;
            }

            if ($locked->status !== ProductStatus::Active) {
                throw LifecycleOperationException::invalidTransition('Archived Products can only return to draft through Restore.');
            }

            $locked->forceFill(['status' => ProductStatus::Draft, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogProductReturnedToDraft, $actor, $locked, [
                'product_uuid' => $locked->uuid,
                'previous_status' => ProductStatus::Active->value,
                'new_status' => ProductStatus::Draft->value,
            ]);

            return $locked->refresh();
        });
    }
}
