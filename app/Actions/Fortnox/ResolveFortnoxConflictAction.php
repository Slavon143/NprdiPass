<?php

namespace App\Actions\Fortnox;

use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Enums\Fortnox\FortnoxField;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxFieldOverride;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\User;
use App\Services\Fortnox\FortnoxSourceOfTruthMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveFortnoxConflictAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly FortnoxSourceOfTruthMatrix $matrix,
        private readonly UpdateProductAction $updateProduct,
        private readonly UpdateProductVariantAction $updateVariant,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, string $conflictUuid, string $resolution): FortnoxSyncConflict
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        if (! in_array($resolution, ['fortnox', 'nordipass'], true)) {
            throw ValidationException::withMessages(['resolution' => 'The resolution must be fortnox or nordipass.']);
        }

        return DB::transaction(function () use ($actor, $company, $conflictUuid, $resolution): FortnoxSyncConflict {
            $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
            $candidate = FortnoxSyncConflict::query()->forCompany($company)->where('uuid', $conflictUuid)->firstOrFail();
            // Match sync's mapping -> product -> variant -> conflict lock order.
            $mapping = FortnoxArticleMapping::query()->forCompany($company)->whereKey($candidate->mapping_id)->lockForUpdate()->firstOrFail();
            $product = Product::query()->forCompany($company)->whereKey($mapping->product_id)->lockForUpdate()->first();
            $variant = $product !== null && $mapping->variant_id !== null
                ? ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->whereKey($mapping->variant_id)->lockForUpdate()->first()
                : null;
            $conflict = FortnoxSyncConflict::query()
                ->forCompany($company)
                ->where('uuid', $conflictUuid)
                ->where('mapping_id', $mapping->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($conflict->status !== FortnoxConflictStatus::Open) {
                throw ValidationException::withMessages(['conflict' => 'This conflict has already been resolved.']);
            }

            $field = $conflict->field;

            if (! $this->matrix->isCatalogWritable($field) || $product === null) {
                throw ValidationException::withMessages(['conflict' => 'This field cannot be resolved through the catalog.']);
            }

            $fortnoxValue = is_array($conflict->fortnox_value)
                ? ($conflict->fortnox_value['value'] ?? null)
                : $conflict->fortnox_value;
            $nordipassValue = is_array($conflict->nordipass_value)
                ? ($conflict->nordipass_value['value'] ?? null)
                : $conflict->nordipass_value;

            $appliedValues = $mapping->last_applied_values ?? [];
            $currentValue = match ($field) {
                FortnoxField::Name => $product->name,
                FortnoxField::Description => $product->description,
                FortnoxField::Gtin => $variant?->gtin,
                default => null,
            };

            if ($resolution === 'fortnox') {
                if ($currentValue !== $nordipassValue) {
                    throw ValidationException::withMessages(['conflict' => 'The local value changed after this conflict was recorded. Refresh and review the conflict before applying Fortnox.']);
                }
                $this->writeField($actor, $company, $field, $fortnoxValue, $product, $variant);
                $appliedValues[$field->value] = $fortnoxValue;
                $status = FortnoxConflictStatus::ResolvedFortnox;
            } else {
                $nordipassValue = $currentValue;
                FortnoxFieldOverride::query()->updateOrCreate(
                    [
                        'company_id' => $company->getKey(),
                        'mapping_id' => $mapping->getKey(),
                        'field' => $field->value,
                    ],
                    [
                        'source' => 'nordipass',
                        'manual_value' => ['value' => $nordipassValue],
                        'overridden_by' => $actor->getKey(),
                        'overridden_at' => now(),
                    ],
                );
                $appliedValues[$field->value] = $nordipassValue;
                $status = FortnoxConflictStatus::ResolvedNordipass;
            }

            $mapping->forceFill(['last_applied_values' => $appliedValues])->save();

            $conflict->forceFill([
                'status' => $status,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => now(),
            ])->save();

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxConflictResolved, $actor, $conflict, [
                'conflict_uuid' => $conflict->uuid,
                'article_number' => $mapping->fortnox_article_number,
                'field' => $field->value,
                'resolution' => $resolution,
            ]);

            return $conflict;
        });
    }

    private function writeField(User $actor, Company $company, FortnoxField $field, mixed $value, Product $product, ?ProductVariant $variant): void
    {
        match ($field) {
            FortnoxField::Name => $this->updateProduct->execute($actor, $company, $product, ['name' => (string) ($value ?? '')]),
            FortnoxField::Description => $this->updateProduct->execute($actor, $company, $product, ['description' => $value === null ? null : (string) $value]),
            FortnoxField::Gtin => $variant !== null && $this->updateVariant->execute($actor, $company, $product, $variant, ['gtin' => $value === null ? null : (string) $value]),
            default => throw ValidationException::withMessages(['conflict' => 'This field cannot be resolved through the catalog.']),
        };
    }
}
