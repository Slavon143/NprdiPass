<?php

namespace App\Actions\Catalog\Attributes;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkArchiveAttributesAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return list<string>
     */
    public function execute(User $actor, Company $company, array $uuids): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);

        return DB::transaction(function () use ($actor, $company, $uuids): array {
            $locked = AttributeDefinition::query()
                ->forCompany($company)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $archivedUuids = [];

            foreach ($uuids as $uuid) {
                $definition = $locked->get($uuid);

                if (! $definition instanceof AttributeDefinition) {
                    continue;
                }

                if ($definition->status === AttributeDefinitionStatus::Archived) {
                    continue;
                }

                $definition->forceFill([
                    'status' => AttributeDefinitionStatus::Archived,
                    'updated_by' => $actor->getKey(),
                ])->save();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeArchived, $actor, $definition, [
                    'attribute_uuid' => $definition->uuid,
                    'code' => $definition->code,
                    'data_type' => $definition->type->value,
                    'scope' => $definition->scope->value,
                    'bulk_operation' => true,
                ]);

                $archivedUuids[] = $definition->uuid;
            }

            return $archivedUuids;
        });
    }
}
