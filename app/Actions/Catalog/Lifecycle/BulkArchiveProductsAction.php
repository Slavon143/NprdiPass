<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkArchiveProductsAction
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
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogArchive);

        return DB::transaction(function () use ($actor, $company, $uuids): array {
            $locked = Product::query()
                ->forCompany($company)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $archivedUuids = [];

            foreach ($uuids as $uuid) {
                $product = $locked->get($uuid);

                if (! $product instanceof Product) {
                    continue;
                }

                if ($product->status === ProductStatus::Archived) {
                    continue;
                }

                $previous = $product->status;
                $product->forceFill([
                    'status' => ProductStatus::Archived,
                    'updated_by' => $actor->getKey(),
                ])->save();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogProductArchived, $actor, $product, [
                    'product_uuid' => $product->uuid,
                    'previous_status' => $previous->value,
                    'new_status' => ProductStatus::Archived->value,
                    'bulk_operation' => true,
                ]);

                $archivedUuids[] = $product->uuid;
            }

            return $archivedUuids;
        });
    }
}
