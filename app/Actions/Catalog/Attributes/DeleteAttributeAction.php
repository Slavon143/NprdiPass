<?php

namespace App\Actions\Catalog\Attributes;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAttributeAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, AttributeDefinition $definition): AttributeDefinition
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);

        return DB::transaction(function () use ($actor, $company, $definition): AttributeDefinition {
            $locked = AttributeDefinition::query()
                ->forCompany($company)
                ->whereKey($definition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoValues($company, $locked);

            $locked->load('options');
            $uuid = $locked->uuid;
            $name = $locked->name;
            $code = $locked->code;

            $locked->forceFill(['updated_by' => $actor->getKey()])->save();
            $locked->delete();

            $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeArchived, $actor, null, [
                'attribute_uuid' => $uuid,
                'attribute_name' => $name,
                'attribute_code' => $code,
                'action' => 'deleted',
            ]);

            return $locked;
        });
    }

    private function assertNoValues(Company $company, AttributeDefinition $definition): void
    {
        $productCount = $definition->productValues()->count();
        $variantCount = $definition->variantValues()->count();

        if ($productCount > 0 || $variantCount > 0) {
            $parts = [];

            if ($productCount > 0) {
                $parts[] = trans_choice(':count product|:count products', $productCount, ['count' => $productCount]);
            }

            if ($variantCount > 0) {
                $parts[] = trans_choice(':count variant|:count variants', $variantCount, ['count' => $variantCount]);
            }

            throw AttributeOperationException::blocked(
                __('Attribute «:name» is used in ', ['name' => $definition->name])
                .implode(' and ', $parts).'.'
            );
        }
    }
}
