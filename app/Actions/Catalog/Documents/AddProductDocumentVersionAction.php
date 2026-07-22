<?php

namespace App\Actions\Catalog\Documents;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
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

                $type = ProductDocumentType::from($data['document_type']);
                $autoApproved = (bool) config('documents.auto_approve_new_versions', false) || ! $type->requiresReview();
                $reviewStatus = $autoApproved ? ProductDocumentReviewStatus::Approved : ProductDocumentReviewStatus::Draft;
                $approvalStatus = $autoApproved ? ProductDocumentApprovalStatus::Approved : ProductDocumentApprovalStatus::Pending;

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
                    'metadata' => $data['metadata'] ?? null,
                    'review_status' => $reviewStatus->value,
                    'approval_status' => $approvalStatus->value,
                    'issuer_name' => $data['issuer_name'] ?? null,
                    'certificate_number' => $this->normalizeCertificateNumber($data['certificate_number'] ?? null),
                    'issuing_body' => $data['issuing_body'] ?? null,
                    'declaration_identifier' => $data['declaration_identifier'] ?? null,
                    'evidence_type' => $data['evidence_type'] ?? null,
                    'topic_code' => $data['topic_code'] ?? null,
                    'standard_reference' => $data['standard_reference'] ?? null,
                    'applicable_market' => $data['applicable_market'] ?? null,
                    'reference_url' => $data['reference_url'] ?? null,
                    'issue_date' => $data['issue_date'] ?? null,
                    'valid_from' => $data['valid_from'] ?? ($data['issue_date'] ?? null),
                    'valid_until' => $data['valid_until'] ?? ($data['expires_at'] ?? null),
                    'expires_at' => $data['expires_at'] ?? null,
                    'original_filename' => $pdf->originalFilename,
                    'safe_display_filename' => $this->safeDisplayFilename($pdf->originalFilename),
                    'mime_type' => $pdf->mimeType,
                    'file_extension' => $pdf->extension,
                    'size_bytes' => $pdf->sizeBytes,
                    'checksum_sha256' => $pdf->checksum,
                    'storage_key' => $storageKey,
                    'file_available' => true,
                    'approved_at' => $autoApproved ? now() : null,
                    'approved_by_user_id' => $autoApproved ? $actor->getKey() : null,
                    'created_by_user_id' => $actor->getKey(),
                ])->save();

                if ($autoApproved) {
                    $lockedDocument->forceFill([
                        'current_version_id' => $version->getKey(),
                        'updated_by_user_id' => $actor->getKey(),
                    ])->save();
                }

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

    private function safeDisplayFilename(string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._\-\s]/u', '', $filename);

        return mb_substr(trim($safe ?: 'document.pdf'), 0, 255);
    }

    private function normalizeCertificateNumber(mixed $certificateNumber): ?string
    {
        if (! is_string($certificateNumber)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', trim($certificateNumber));

        return $normalized === '' ? null : mb_substr($normalized, 0, 120);
    }

    private function assertDocumentActive(ProductDocument $document): void
    {
        if ($document->status !== ProductDocumentStatus::Active) {
            throw new \RuntimeException('New versions can only be added to active documents.');
        }
    }
}
