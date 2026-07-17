<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Events\Passports\ProductPassportUnpublished;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UnpublishProductPassport
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
    ): ProductPassport {
        DB::beginTransaction();

        try {
            $freshCompany = $this->authorize($actor, $company);
            $this->assertProductBelongsToCompany($freshCompany, $product);
            $this->assertPassportBelongsToProduct($passport, $product);

            $passport = ProductPassport::query()
                ->whereKey($passport->getKey())
                ->lockForUpdate()
                ->first();

            $publishedVersion = $passport->currentPublishedVersion;

            if ($publishedVersion === null) {
                throw new ConflictHttpException('Passport has no published version to unpublish.');
            }

            $publishedVersion->setAttribute('status', ProductPassportVersionStatus::Withdrawn);
            $publishedVersion->setAttribute('withdrawn_at', now());
            $publishedVersion->save();

            $passport->setAttribute('current_published_version_id', null);
            $passport->setAttribute('status', ProductPassportStatus::Unpublished);
            $passport->setAttribute('unpublished_at', now());
            $passport->setAttribute('updated_by', $actor->getKey());
            $passport->save();

            $this->auditLogger->logTenant(
                $freshCompany,
                AuditEvent::PassportUnpublished,
                $actor,
                $passport,
                [
                    'product_uuid' => $product->getAttribute('uuid'),
                    'passport_uuid' => $passport->getAttribute('uuid'),
                    'published_version_uuid' => $publishedVersion->getAttribute('uuid'),
                    'version_number' => $publishedVersion->version_number,
                ],
            );

            DB::commit();

            $passport->loadMissing(['currentDraftVersion', 'currentPublishedVersion']);

            event(new ProductPassportUnpublished($passport, $publishedVersion, $actor));

            return $passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function authorize(User $actor, Company $company): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, CompanyPermission::PassportsManage);

        return $freshCompany;
    }

    private function assertProductBelongsToCompany(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function assertPassportBelongsToProduct(ProductPassport $passport, Product $product): void
    {
        if ((int) $passport->getAttribute('product_id') !== (int) $product->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
