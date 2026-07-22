<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\DppPayloadValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SyncProductPassportDocumentsAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly DppPayloadNormalizer $normalizer,
        private readonly DppPayloadValidator $validator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $documentReferences
     */
    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        array $documentReferences,
        int $expectedRevision,
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

            $draft = $passport->currentDraftVersion;

            if ($draft === null || $draft->status !== ProductPassportVersionStatus::Draft) {
                throw new ConflictHttpException('No draft version available.');
            }

            if ($draft->draft_revision !== $expectedRevision) {
                throw new ConflictHttpException(
                    "Revision conflict: expected revision {$expectedRevision}, current revision {$draft->draft_revision}."
                );
            }

            $payload = $draft->payload ?? [];
            $payload['document_references'] = $documentReferences;

            $this->validator->validateFullPayload($payload, $freshCompany, $passport);

            $normalized = $this->normalizer->normalize($payload);

            $oldRevision = $draft->draft_revision;
            $newRevision = $oldRevision + 1;

            $draft->setAttribute('payload', $normalized);
            $draft->setAttribute('draft_revision', $newRevision);
            $draft->setAttribute('updated_by', $actor->getKey());
            $draft->save();

            $this->auditLogger->logTenant(
                $freshCompany,
                AuditEvent::PassportDraftUpdated,
                $actor,
                $passport,
                [
                    'product_uuid' => $product->getAttribute('uuid'),
                    'passport_uuid' => $passport->getAttribute('uuid'),
                    'draft_version_uuid' => $draft->getAttribute('uuid'),
                    'section_key' => 'certifications_and_documents',
                    'changed_sections' => ['certifications_and_documents'],
                    'old_revision' => $oldRevision,
                    'new_revision' => $newRevision,
                ],
            );

            DB::commit();

            return $passport->fresh(['currentDraftVersion']);
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
