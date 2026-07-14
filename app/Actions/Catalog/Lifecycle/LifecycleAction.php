<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

abstract class LifecycleAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    protected function authorize(User $actor, Company $company, CompanyPermission $permission): Company
    {
        $freshCompany = Company::query()->find($company->getKey());
        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, $permission);

        return $freshCompany;
    }

    protected function assertProduct(Company $company, Product $product): void
    {
        if ((int) $product->company_id !== (int) $company->getKey() || $product->trashed()) {
            throw LifecycleOperationException::unavailable();
        }
    }

    protected function assertVariant(Company $company, Product $product, ProductVariant $variant): void
    {
        if ((int) $variant->company_id !== (int) $company->getKey()
            || (int) $variant->product_id !== (int) $product->getKey()
            || $variant->trashed()) {
            throw LifecycleOperationException::unavailable();
        }
    }
}
