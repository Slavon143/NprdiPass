<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogProductCapacity;
use Illuminate\Support\Facades\DB;

class RestoreProductAction extends LifecycleAction
{
    public function __construct(
        CompanyAuthorizer $authorizer,
        AuditLogger $auditLogger,
        private readonly CatalogProductCapacity $capacity,
    ) {
        parent::__construct($authorizer, $auditLogger);
    }

    public function execute(User $actor, Company $company, Product $product): Product
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
        $this->assertProduct($company, $product);

        return DB::transaction(function () use ($actor, $company, $product): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
            $this->capacity->lockCapacity($company);
            $locked = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ProductStatus::Draft) {
                return $locked;
            }

            if ($locked->status !== ProductStatus::Archived) {
                throw LifecycleOperationException::invalidTransition('Only an archived Product can be restored.');
            }

            if ($locked->managed) {
                $this->capacity->assertSlotAvailable($company);
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
