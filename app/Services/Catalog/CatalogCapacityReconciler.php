<?php

namespace App\Services\Catalog;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\CatalogCapacity;
use App\Models\Catalog\Product;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use LogicException;

class CatalogCapacityReconciler
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @return array{company_uuid: string, previous_count: int|null, actual_count: int, previous_total: int|null, actual_total: int, repaired: bool} */
    public function reconcile(int $companyId, bool $repair = false): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Capacity reconciliation requires its own fresh root transaction.');
        }

        return DB::transaction(function () use ($companyId, $repair): array {
            // All cardinality changes acquire the capacity mutex, including SQL triggers.
            // The first consistent read is established only after taking it.
            $company = Company::withTrashed()->whereKey($companyId)->sharedLock()->firstOrFail();
            $capacity = CatalogCapacity::query()->where('company_id', $companyId)->lockForUpdate()->first();
            if ($capacity === null) {
                if (! $repair) {
                    throw new LogicException('Missing catalogue capacity row; explicit repair is required.');
                }
                $capacity = CatalogCapacity::query()->forceCreate(['company_id' => $companyId, 'products_count' => null]);
            }
            $actual = $company->products()->where('managed', true)->whereIn('status', [ProductStatus::Draft->value, ProductStatus::Active->value])->count();
            $actualTotal = Product::withTrashed()->forCompany($company)->count();
            $previous = $capacity->products_count;
            $previousTotal = $capacity->total_products_count;
            $changed = $previous !== $actual || $previousTotal !== $actualTotal;
            $result = ['company_uuid' => $company->uuid, 'previous_count' => $previous,
                'actual_count' => $actual, 'previous_total' => $previousTotal,
                'actual_total' => $actualTotal, 'repaired' => $repair && $changed];

            if ($result['repaired']) {
                DB::table('catalog_product_capacities')->where('company_id', $companyId)->update([
                    'products_count' => $actual, 'total_products_count' => $actualTotal,
                ]);
            }
            if ($changed) {
                $this->auditLogger->logTenant($company, AuditEvent::CatalogCapacityReconciled, subject: $company, properties: $result);
            }

            return $result;
        }, 5);
    }
}
