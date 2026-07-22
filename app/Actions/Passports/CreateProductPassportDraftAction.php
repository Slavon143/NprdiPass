<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CreateProductPassportDraftAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly DppPayloadNormalizer $normalizer,
        private readonly ReadinessProfileRepository $profileRepository,
    ) {}

    public function handle(User $actor, Company $company, Product $product): ProductPassport
    {
        DB::beginTransaction();

        try {
            $freshCompany = $this->authorize($actor, $company);
            $this->assertProductBelongsToCompany($freshCompany, $product);
            $this->assertProductNotArchived($product);

            $existingPassport = ProductPassport::query()
                ->forCompany($freshCompany)
                ->where('product_id', $product->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingPassport !== null) {
                if ($existingPassport->isDraft()) {
                    DB::rollBack();

                    return $existingPassport;
                }

                throw new ConflictHttpException('A passport already exists for this product and is not in draft state.');
            }

            $sections = array_map(fn ($s) => $s->value, DppSectionKey::cases());
            $defaultLanguage = config('passports.default_language', 'sv');
            $locale = $defaultLanguage;

            $emptyPayload = [
                'enabled_sections' => $sections,
                'data' => [],
                'translations' => [
                    $locale => [],
                ],
                'document_references' => [],
            ];

            $normalized = $this->normalizer->normalize($emptyPayload);

            $passport = new ProductPassport;
            $passport->setAttribute('company_id', $freshCompany->getKey());
            $passport->setAttribute('product_id', $product->getKey());
            $passport->setAttribute('status', ProductPassportStatus::Draft);
            $passport->setAttribute('default_language', $defaultLanguage);
            $passport->setAttribute('enabled_languages', [$defaultLanguage]);
            $passport->setAttribute('created_by', $actor->getKey());
            $passport->save();

            $version = new ProductPassportVersion;
            $version->setAttribute('company_id', $freshCompany->getKey());
            $version->setAttribute('passport_id', $passport->getKey());
            $version->setAttribute('status', ProductPassportVersionStatus::Draft);
            $version->setAttribute('draft_revision', 1);
            $version->setAttribute('schema_version', '1');
            $version->setAttribute('payload', $normalized);
            $activeProfile = $this->profileRepository->active();
            $version->setAttribute('readiness_profile', $activeProfile->code);
            $version->setAttribute('readiness_profile_version', $activeProfile->version);
            $version->setAttribute('readiness_rule_set_fingerprint', $activeProfile->fingerprint);
            $version->setAttribute('created_by', $actor->getKey());
            $version->save();

            $passport->setAttribute('current_draft_version_id', $version->getKey());
            $passport->save();

            $this->auditLogger->logTenant(
                $freshCompany,
                AuditEvent::PassportCreated,
                $actor,
                $passport,
                [
                    'product_uuid' => $product->getAttribute('uuid'),
                    'passport_uuid' => $passport->getAttribute('uuid'),
                    'draft_version_uuid' => $version->getAttribute('uuid'),
                    'readiness_profile' => $activeProfile->code,
                    'readiness_profile_version' => $activeProfile->version,
                    'readiness_rule_set_fingerprint' => $activeProfile->fingerprint,
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

    private function assertProductNotArchived(Product $product): void
    {
        if ($product->status === ProductStatus::Archived) {
            throw new \RuntimeException('Cannot create passport for archived product.');
        }
    }
}
