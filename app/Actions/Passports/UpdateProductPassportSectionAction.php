<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Data\Passports\DppSectionDefinition;
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
use App\Services\Passports\DppSchemaRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateProductPassportSectionAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly DppPayloadNormalizer $normalizer,
        private readonly DppPayloadValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        string $sectionKey,
        array $sectionPayload,
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

            $sections = $this->getSectionDefinitions();
            $sectionDef = $sections[$sectionKey] ?? null;

            if ($sectionDef === null) {
                throw ValidationException::withMessages(['section' => ['Unknown section.']]);
            }

            $isTranslatable = $sectionDef->translatable;
            $defaultLanguage = $passport->default_language;

            if ($isTranslatable) {
                $this->validator->validateSectionPayload($sectionKey, $sectionPayload, true, $defaultLanguage);
                $translatableFields = $this->buildTranslatableFields($sectionDef, $sectionPayload);
                $nonTranslatableFields = $this->buildNonTranslatableFields($sectionDef, $sectionPayload);

                if ($translatableFields !== []) {
                    $payload['translations'][$defaultLanguage][$sectionKey] = $translatableFields;
                }

                if ($nonTranslatableFields !== []) {
                    $payload['data'][$sectionKey] = $nonTranslatableFields;
                }
            } else {
                $this->validator->validateSectionPayload($sectionKey, $sectionPayload, false);
                $nonTranslatableFields = $this->buildNonTranslatableFields($sectionDef, $sectionPayload);
                $payload['data'][$sectionKey] = $nonTranslatableFields;
            }

            $payload['enabled_sections'] = $this->ensureSectionEnabled($payload['enabled_sections'] ?? [], $sectionKey);

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
                ],
            );

            DB::commit();

            return $passport->fresh(['currentDraftVersion']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     * @return array<string, mixed>
     */
    private function buildTranslatableFields(DppSectionDefinition $sectionDef, array $sectionPayload): array
    {
        $result = [];

        foreach ($sectionDef->fields as $field) {
            if (! $field->translatable) {
                continue;
            }

            if (array_key_exists($field->key, $sectionPayload)) {
                $value = $sectionPayload[$field->key];

                if ($value !== null && $value !== '') {
                    $result[$field->key] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     * @return array<string, mixed>
     */
    private function buildNonTranslatableFields(DppSectionDefinition $sectionDef, array $sectionPayload): array
    {
        $result = [];

        foreach ($sectionDef->fields as $field) {
            if ($field->translatable) {
                continue;
            }

            if (array_key_exists($field->key, $sectionPayload)) {
                $value = $sectionPayload[$field->key];

                if ($value !== null && $value !== '' && $value !== []) {
                    $result[$field->key] = $value;
                }
            }
        }

        return $result;
    }

    /** @return array<string, DppSectionDefinition> */
    private function getSectionDefinitions(): array
    {
        return app(DppSchemaRegistry::class)->sections();
    }

    /** @param string[] $enabledSections */
    private function ensureSectionEnabled(array $enabledSections, string $sectionKey): array
    {
        foreach ($enabledSections as $s) {
            if ($s === $sectionKey) {
                return $enabledSections;
            }
        }

        $enabledSections[] = $sectionKey;

        return $enabledSections;
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

    private function authorize(User $actor, Company $company): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, CompanyPermission::PassportsManage);

        return $freshCompany;
    }
}
