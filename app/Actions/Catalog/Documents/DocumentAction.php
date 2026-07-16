<?php

namespace App\Actions\Catalog\Documents;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class DocumentAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    protected function authorize(User $actor, Company $company, CompanyPermission $permission): Company
    {
        $this->authorizer->authorize($actor, $company, $permission);

        return $company;
    }

    protected function assertTenant(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    protected function assertProductCanAcceptDocuments(Product $product): void
    {
        if ($product->status === ProductStatus::Archived) {
            throw new \RuntimeException('Documents cannot be created for archived products.');
        }
    }
}
