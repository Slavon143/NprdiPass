<?php

namespace App\Actions\Catalog\Documents;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Documents\ProductDocumentStatus;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Services\Catalog\Documents\PdfDocumentValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddProductDocumentVersionAction extends DocumentAction
{
    public function __construct(
        CompanyAuthorizer $authorizer,
        AuditLogger $auditLogger,
        private readonly PdfDocumentValidator $pdfValidator,
        private readonly DocumentFileStorage $storage,
    ) {
        parent::__construct($authorizer, $auditLogger);
    }

    public function execute(User $actor, Company $company, ProductDocument $document, array $data, UploadedFile $file): ProductDocumentVersion
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogManageDocuments);
        $this->assertDocumentActive($document);

        $product = $document->product;
        $this->assertTenant($company, $product);
        $this->assertProductCanAcceptDocuments($product);

        $pdf = $this->pdfValidator->validate($file);

        $versionUuid = Str::uuid()->toString();
        $storageKey = $this->storage->buildStorageKey($company, $product, $document->uuid, $versionUuid);

        if ($this->storage->exists($storageKey)) {
            throw new \RuntimeException('A file already exists at the target storage location.');
        }

        $this->storage->put($storageKey, $pdf);
        $this->storage->verifyChecksum($storageKey, $pdf->checksum);

        try {
            return DB::transaction(function () use ($actor, $company, $document, $data, $pdf, $versionUuid, $storageKey): ProductDocumentVersion {
                $this->authorize($actor, $company, CompanyPermission::CatalogManageDocuments);

                $lockedDocument = ProductDocument::query()->forCompany($company)
                    ->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                $this->assertDocumentActive($lockedDocument);

                $maxVersion = ProductDocumentVersion::query()
                    ->where('company_id', $company->getKey())
                    ->where('document_id', $lockedDocument->getKey())
                    ->max('version_number') ?? 0;

                $nextVersion = $maxVersion + 1;

                $version = new ProductDocumentVersion;
                $version->forceFill([
                    'uuid' => $versionUuid,
                    'company_id' => $company->getKey(),
                    'document_id' => $lockedDocument->getKey(),
                    'version_number' => $nextVersion,
                    'document_type' => $data['document_type'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'language' => $data['language'],
                    'visibility' => $data['visibility'],
                    'issuer_name' => $data['issuer_name'] ?? null,
                    'issue_date' => $data['issue_date'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'original_filename' => $pdf->originalFilename,
                    'mime_type' => $pdf->mimeType,
                    'file_extension' => $pdf->extension,
                    'size_bytes' => $pdf->sizeBytes,
                    'checksum_sha256' => $pdf->checksum,
                    'storage_key' => $storageKey,
                    'created_by_user_id' => $actor->getKey(),
                ])->save();

                $lockedDocument->forceFill([
                    'current_version_id' => $version->getKey(),
                    'updated_by_user_id' => $actor->getKey(),
                ])->save();

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogDocumentVersionAdded,
                    $actor,
                    $document,
                    [
                        'product_uuid' => $lockedDocument->product->uuid,
                        'document_uuid' => $lockedDocument->uuid,
                        'version_uuid' => $versionUuid,
                        'version_number' => $nextVersion,
                        'document_type' => $data['document_type'],
                        'language' => $data['language'],
                        'visibility' => $data['visibility'],
                    ],
                );

                return $version;
            });
        } catch (\Throwable $e) {
            if ($this->storage->exists($storageKey)) {
                $this->storage->delete($storageKey);
            }
            throw $e;
        }
    }

    private function assertDocumentActive(ProductDocument $document): void
    {
        if ($document->status !== ProductDocumentStatus::Active) {
            throw new \RuntimeException('New versions can only be added to active documents.');
        }
    }
}
