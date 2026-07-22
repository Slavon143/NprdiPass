<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateProductPassportSettingsAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly DppPayloadNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        array $settings,
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

            if (isset($settings['enabled_sections']) && is_array($settings['enabled_sections'])) {
                $newEnabled = $settings['enabled_sections'];
                $coreSections = array_filter($newEnabled, function ($s) {
                    $section = DppSectionKey::tryFrom($s);

                    return $section && $section->isCore();
                });
                $allCoreKeys = array_map(
                    fn ($s) => $s->value,
                    array_filter(DppSectionKey::cases(), fn ($s) => $s->isCore()),
                );

                $missingCore = array_diff($allCoreKeys, $coreSections);

                if ($missingCore !== []) {
                    throw ValidationException::withMessages([
                        'enabled_sections' => ['Core sections cannot be disabled: '.implode(', ', $missingCore)],
                    ]);
                }

                $payload['enabled_sections'] = $newEnabled;
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
                    'section_key' => 'settings',
                    'changed_sections' => array_values($normalized['enabled_sections'] ?? []),
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
