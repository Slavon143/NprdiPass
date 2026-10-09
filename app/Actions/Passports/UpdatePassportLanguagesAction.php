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
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Catalog\ManagedProductGate;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\Localization\PassportLocaleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdatePassportLanguagesAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly PassportLocaleRegistry $localeRegistry,
        private readonly DppPayloadNormalizer $normalizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $enabledLanguages
     */
    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        string $defaultLanguage,
        array $enabledLanguages,
    ): ProductPassport {
        DB::beginTransaction();

        try {
            $this->authorize($actor, $company, $passport, $product);
            $product = app(ManagedProductGate::class)->forMutation($company, $product);

            $passport = ProductPassport::query()
                ->forCompany($company)
                ->where('product_id', $product->getKey())
                ->whereKey($passport->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->localeRegistry->supports($defaultLanguage)) {
                throw new ConflictHttpException("Unsupported default language: {$defaultLanguage}.");
            }

            if (! in_array($defaultLanguage, $enabledLanguages, true)) {
                throw new ConflictHttpException('Default language must be enabled.');
            }

            foreach ($enabledLanguages as $lang) {
                if (! $this->localeRegistry->supports($lang)) {
                    throw new ConflictHttpException("Unsupported language: {$lang}.");
                }
            }

            if (count($enabledLanguages) !== count(array_unique($enabledLanguages))) {
                throw new ConflictHttpException('Duplicate language codes are not allowed.');
            }

            $passport->setAttribute('default_language', $defaultLanguage);
            $passport->setAttribute('enabled_languages', $enabledLanguages);
            $passport->setAttribute('updated_by', $actor->getKey());
            $passport->save();

            // Language configuration lives in the draft payload so it becomes
            // part of the immutable snapshot on publication. The published
            // version keeps the languages it was published with.
            $draft = ProductPassportVersion::query()
                ->forCompany($company)
                ->where('passport_id', $passport->getKey())
                ->whereKey($passport->getAttribute('current_draft_version_id'))
                ->lockForUpdate()
                ->first();

            if ($draft !== null && $draft->status === ProductPassportVersionStatus::Draft) {
                $payload = $draft->payload ?? [];
                $payload['languages'] = [
                    'default' => $defaultLanguage,
                    'enabled' => $enabledLanguages,
                ];

                $oldRevision = $draft->draft_revision;

                $draft->setAttribute('payload', $this->normalizer->normalize($payload));
                $draft->setAttribute('draft_revision', $oldRevision + 1);
                $draft->setAttribute('updated_by', $actor->getKey());
                $draft->save();

                $this->auditLogger->logTenant($company, AuditEvent::PassportDraftUpdated, $actor, $passport, [
                    'product_uuid' => $product->getAttribute('uuid'),
                    'passport_uuid' => $passport->getAttribute('uuid'),
                    'draft_version_uuid' => $draft->getAttribute('uuid'),
                    'section_key' => 'languages',
                    'default_language' => $defaultLanguage,
                    'enabled_languages' => $enabledLanguages,
                    'old_revision' => $oldRevision,
                    'new_revision' => $oldRevision + 1,
                ]);
            }

            DB::commit();

            return $passport->fresh(['currentDraftVersion']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function authorize(User $actor, Company $company, ProductPassport $passport, Product $product): void
    {
        if ($company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        if ((int) $passport->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }

        if ((int) $passport->getAttribute('product_id') !== (int) $product->getKey()) {
            throw new NotFoundHttpException;
        }

        $this->authorizer->authorize($actor, $company, CompanyPermission::PassportsManage);
    }
}
