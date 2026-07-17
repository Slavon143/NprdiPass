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
use App\Services\Passports\DppSchemaRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResetProductPassportSectionAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly DppPayloadNormalizer $normalizer,
    ) {}

    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        string $sectionKey,
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
            $sections = app(DppSchemaRegistry::class)->sections();
            $sectionDef = $sections[$sectionKey] ?? null;

            if ($sectionDef === null) {
                throw ValidationException::withMessages(['section' => ['Unknown section.']]);
            }

            if ($sectionDef->core) {
                throw ValidationException::withMessages(['section' => ['Core sections cannot be reset.']]);
            }

            unset($payload['data'][$sectionKey]);

            $defaultLanguage = $passport->default_language;

            if (isset($payload['translations'][$defaultLanguage])) {
                unset($payload['translations'][$defaultLanguage][$sectionKey]);
            }

            if ($sectionKey === 'certifications_and_documents') {
                $payload['document_references'] = [];
            }

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
                    'section_key' => $sectionKey,
                    'old_revision' => $oldRevision,
                    'new_revision' => $newRevision,
                    'action' => 'reset',
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
