<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ManagedProductGate
{
    public function forMutation(Company $company, Product $product): Product
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DPP enrollment must be checked inside the mutation transaction.');
        }
        $current = Product::query()->forCompany($company)->whereKey($product->getKey())->sharedLock()->firstOrFail();
        $this->assertManaged($current);

        return $current;
    }

    public function assertManaged(Product $product): void
    {
        if (! $product->managed || $product->status === ProductStatus::Archived) {
            throw new ConflictHttpException('Enroll and restore this product in the managed catalogue before changing its DPP.');
        }
    }
}
