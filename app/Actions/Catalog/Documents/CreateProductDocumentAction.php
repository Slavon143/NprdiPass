<?php

namespace App\Actions\Catalog\Documents;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Documents\ProductDocumentStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Services\Catalog\Documents\PdfDocumentValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProductDocumentAction extends DocumentAction
{
    public function __construct(
        CompanyAuthorizer $authorizer,
        AuditLogger $auditLogger,
        private readonly PdfDocumentValidator $pdfValidator,
        private readonly DocumentFileStorage $storage,
    ) {
        parent::__construct($authorizer, $auditLogger);
    }

    public function execute(User $actor, Company $company, Product $product, array $data, UploadedFile $file): ProductDocument
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogManageDocuments);
        $this->assertTenant($company, $product);
        $this->assertProductCanAcceptDocuments($product);

        $pdf = $this->pdfValidator->validate($file);

        $documentUuid = Str::uuid()->toString();
        $versionUuid = Str::uuid()->toString();

        $storageKey = $this->storage->buildStorageKey($company, $product, $documentUuid, $versionUuid);

        if ($this->storage->exists($storageKey)) {
            throw new \RuntimeException('A file already exists at the target storage location.');
        }

        $this->storage->put($storageKey, $pdf);
        $this->storage->verifyChecksum($storageKey, $pdf->checksum);

        try {
            return DB::transaction(function () use ($actor, $company, $product, $data, $pdf, $documentUuid, $versionUuid, $storageKey): ProductDocument {
                $this->authorize($actor, $company, CompanyPermission::CatalogManageDocuments);

                $product = Product::query()->forCompany($company)
                    ->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

                $document = new ProductDocument;
                $document->forceFill([
                    'uuid' => $documentUuid,
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'status' => ProductDocumentStatus::Active->value,
                    'created_by_user_id' => $actor->getKey(),
                ])->save();

                $version = new ProductDocumentVersion;
                $version->forceFill([
                    'uuid' => $versionUuid,
                    'company_id' => $company->getKey(),
                    'document_id' => $document->getKey(),
                    'version_number' => 1,
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

                $document->forceFill([
                    'current_version_id' => $version->getKey(),
                ])->save();

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogDocumentCreated,
                    $actor,
                    $document,
                    [
                        'product_uuid' => $product->uuid,
                        'document_uuid' => $documentUuid,
                        'version_uuid' => $versionUuid,
                        'version_number' => 1,
                        'document_type' => $data['document_type'],
                        'language' => $data['language'],
                        'visibility' => $data['visibility'],
                    ],
                );

                return $document->load('currentVersion');
            });
        } catch (\Throwable $e) {
            if ($this->storage->exists($storageKey)) {
                $this->storage->delete($storageKey);
            }
            throw $e;
        }
    }
}
