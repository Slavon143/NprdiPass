<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveProductAction extends LifecycleAction
{
    public function execute(User $actor, Company $company, Product $product): Product
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
        $this->assertProduct($company, $product);

        return DB::transaction(function () use ($actor, $company, $product): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
            $locked = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ProductStatus::Archived) {
                return $locked;
            }

            $previous = $locked->status;
            $locked->forceFill(['status' => ProductStatus::Archived, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogProductArchived, $actor, $locked, [
                'product_uuid' => $locked->uuid,
                'previous_status' => $previous->value,
                'new_status' => ProductStatus::Archived->value,
            ]);

            return $locked->refresh();
        });
    }
}
