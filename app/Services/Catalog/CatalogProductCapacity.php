<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\CatalogCapacity;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Services\Billing\EntitlementService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CatalogProductCapacity
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function count(Company $company): int
    {
        return $this->productsInCatalog($company)->count();
    }

    public function assertSlotAvailable(Company $company): void
    {
        $locked = $this->lockCapacity($company);
        $limit = $this->entitlements->limitForMutation($company, 'catalog.products.max', sharedCompany: true);
        if ($limit === null) {
            if ($company->commercial_model_version === 1) {
                throw ValidationException::withMessages(['entitlement' => 'The managed product limit is unavailable.']);
            }

            return;
        }

        // The locked capacity row is a current read, including behind older caller
        // snapshots. Triggers maintain known counts in the product transaction.
        // Unknown counts retain the shared covering-index probe until backfill.
        $full = $limit <= 0 || ($locked->products_count !== null
            ? $locked->products_count >= $limit
            : $this->productsInCatalog($company)
                ->forceIndex('products_managed_capacity_index')->reorder()->offset($limit - 1)->limit(1)->sharedLock()->first(['id']) !== null);
        if ($full) {
            throw ValidationException::withMessages([
                'entitlement' => "The catalog.products.max entitlement limit of {$limit} has been reached.",
            ]);
        }
    }

    public function assertPoolSlotAvailable(Company $company): void
    {
        $locked = $this->lockCapacity($company);
        if ($company->commercial_model_version !== 1) {
            return;
        }
        $limit = $this->entitlements->limitForMutation($company, 'catalog.total.max', sharedCompany: true);
        // Missing commercial configuration is not an unlimited ingestion allowance.
        if ($limit === null || $limit <= 0 || ($locked->total_products_count !== null
            ? $locked->total_products_count >= $limit
            : Product::withTrashed()->forCompany($company)->reorder()->offset($limit - 1)->limit(1)->sharedLock()->first(['id']) !== null)) {
            throw ValidationException::withMessages(['entitlement' => 'The catalogue ingestion limit has been reached or is unavailable.']);
        }
    }

    public function lockCapacity(Company $company): CatalogCapacity
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Product capacity must be checked inside a transaction.');
        }

        Company::query()->whereKey($company->getKey())->sharedLock()->firstOrFail(['id']);

        return CatalogCapacity::query()->where('company_id', $company->getKey())->lockForUpdate()->firstOrFail();
    }

    private function productsInCatalog(Company $company): HasMany
    {
        return $company->products()->where('managed', true)->whereIn('status', [ProductStatus::Draft->value, ProductStatus::Active->value]);
    }
}
