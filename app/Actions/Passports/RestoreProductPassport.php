<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Events\Passports\ProductPassportRestored;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RestoreProductPassport
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

            if (! $passport->isArchived()) {
                throw new ConflictHttpException('Only archived passports can be restored.');
            }

            $passport->setAttribute('status', ProductPassportStatus::Draft);
            $passport->setAttribute('archived_at', null);
            $passport->setAttribute('updated_by', $actor->getKey());
            $passport->save();

            $existingDraft = ProductPassportVersion::query()
                ->where('passport_id', $passport->getKey())
                ->where('status', ProductPassportVersionStatus::Draft->value)
                ->first();

            if ($existingDraft === null) {
                $lastPayload = [];
                $lastSchemaVersion = '1.0';

                $lastVersion = ProductPassportVersion::query()
                    ->where('passport_id', $passport->getKey())
                    ->orderByDesc('id')
                    ->first();

                if ($lastVersion !== null) {
                    $lastPayload = $lastVersion->payload ?? [];
                    $lastSchemaVersion = $lastVersion->schema_version ?? '1.0';
                }

                $revision = 1;

                $lastDraftRevision = ProductPassportVersion::query()
                    ->where('passport_id', $passport->getKey())
                    ->max('draft_revision');

                if ($lastDraftRevision !== null) {
                    $revision = $lastDraftRevision + 1;
                }

                $newDraft = new ProductPassportVersion;
                $newDraft->setAttribute('company_id', $freshCompany->getKey());
                $newDraft->setAttribute('passport_id', $passport->getKey());
                $newDraft->setAttribute('status', ProductPassportVersionStatus::Draft);
                $newDraft->setAttribute('draft_revision', $revision);
                $newDraft->setAttribute('schema_version', $lastSchemaVersion);
                $newDraft->setAttribute('payload', $lastPayload);
                $newDraft->setAttribute('created_by', $actor->getKey());
                $newDraft->save();

                $passport->setAttribute('current_draft_version_id', $newDraft->getKey());
                $passport->save();
            }

            $this->auditLogger->logTenant(
                $freshCompany,
                AuditEvent::PassportRestored,
                $actor,
                $passport,
                [
                    'product_uuid' => $product->getAttribute('uuid'),
                    'passport_uuid' => $passport->getAttribute('uuid'),
                ],
            );

            DB::commit();

            $passport->loadMissing(['currentDraftVersion', 'currentPublishedVersion']);

            event(new ProductPassportRestored($passport, $actor));

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
