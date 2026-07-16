<?php

namespace App\Actions\Catalog\Documents;

use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Documents\ProductDocumentStatus;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveProductDocumentAction extends DocumentAction
{
    public function execute(User $actor, Company $company, ProductDocument $document): ProductDocument
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogArchiveDocuments);
        $this->assertTenant($company, $document->product);

        if ($document->isArchived()) {
            throw new \RuntimeException('The document is already archived.');
        }

        return DB::transaction(function () use ($actor, $company, $document): ProductDocument {
            $this->authorize($actor, $company, CompanyPermission::CatalogArchiveDocuments);

            $lockedDocument = ProductDocument::query()->forCompany($company)
                ->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedDocument->isArchived()) {
                throw new \RuntimeException('The document is already archived.');
            }

            $lockedDocument->forceFill([
                'status' => ProductDocumentStatus::Archived->value,
                'archived_at' => now(),
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::CatalogDocumentArchived,
                $actor,
                $document,
                [
                    'product_uuid' => $lockedDocument->product->uuid,
                    'document_uuid' => $lockedDocument->uuid,
                ],
            );

            return $lockedDocument;
        });
    }
}
