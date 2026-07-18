<?php

namespace App\Actions\Catalog\Attributes;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkDeleteAttributesAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{deleted: list<string>, blocked: list<array{uuid: string, name: string, reason: string}>}
     */
    public function execute(User $actor, Company $company, array $uuids): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);

        return DB::transaction(function () use ($actor, $company, $uuids): array {
            $all = AttributeDefinition::query()
                ->forCompany($company)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $ids = $all->pluck('id')->all();

            $productCounts = DB::table('product_attribute_values')
                ->where('company_id', $company->getKey())
                ->whereIn('attribute_definition_id', $ids)
                ->get(['attribute_definition_id'])
                ->groupBy('attribute_definition_id')
                ->map(fn ($group) => $group->count());

            $variantCounts = DB::table('variant_attribute_values')
                ->where('company_id', $company->getKey())
                ->whereIn('attribute_definition_id', $ids)
                ->get(['attribute_definition_id'])
                ->groupBy('attribute_definition_id')
                ->map(fn ($group) => $group->count());

            $deleted = [];
            $blocked = [];

            foreach ($uuids as $uuid) {
                $definition = $all->get($uuid);

                if (! $definition instanceof AttributeDefinition) {
                    continue;
                }

                $productCount = $productCounts->get($definition->getKey(), 0);
                $variantCount = $variantCounts->get($definition->getKey(), 0);

                if ($productCount > 0 || $variantCount > 0) {
                    $parts = [];

                    if ($productCount > 0) {
                        $parts[] = trans_choice(':count product value|:count product values', $productCount, ['count' => $productCount]);
                    }

                    if ($variantCount > 0) {
                        $parts[] = trans_choice(':count variant value|:count variant values', $variantCount, ['count' => $variantCount]);
                    }

                    $blocked[] = [
                        'uuid' => $definition->uuid,
                        'name' => $definition->name,
                        'reason' => implode(', ', $parts),
                    ];

                    continue;
                }

                $attrUuid = $definition->uuid;
                $attrName = $definition->name;
                $attrCode = $definition->code;

                $definition->forceFill(['updated_by' => $actor->getKey()])->save();
                $definition->delete();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeArchived, $actor, null, [
                    'attribute_uuid' => $attrUuid,
                    'attribute_name' => $attrName,
                    'attribute_code' => $attrCode,
                    'action' => 'bulk_deleted',
                ]);

                $deleted[] = $attrUuid;
            }

            return ['deleted' => $deleted, 'blocked' => $blocked];
        });
    }
}
