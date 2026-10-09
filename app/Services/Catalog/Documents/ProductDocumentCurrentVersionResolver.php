<?php

namespace App\Services\Catalog\Documents;

use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentExpiryState;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductDocumentCurrentVersionResolver
{
    public function resolveReference(
        ProductDocument $document,
        ?string $versionUuid,
        ?CarbonImmutable $evaluationDate = null,
    ): ?ProductDocumentVersion {
        if ($versionUuid === null || $versionUuid === '') {
            return $this->resolve($document, true, $evaluationDate);
        }
        if (! $document->isActive()) {
            return null;
        }

        $version = $document->relationLoaded('readinessVersions')
            ? $this->loadedEligibleVersions($document, true)->firstWhere('uuid', $versionUuid)
            : $this->baseQuery($document, true)->where('uuid', $versionUuid)->first();
        if ($version === null || in_array($version->expiryState($evaluationDate), [
            ProductDocumentExpiryState::Expired,
            ProductDocumentExpiryState::NotYetValid,
            ProductDocumentExpiryState::Unknown,
        ], true)) {
            return null;
        }

        return $version;
    }

    public function resolve(
        ProductDocument $document,
        bool $publicOnly = false,
        ?CarbonImmutable $evaluationDate = null,
    ): ?ProductDocumentVersion {
        if (! $document->isActive()) {
            return null;
        }

        $versions = $document->relationLoaded('readinessVersions')
            ? $this->loadedEligibleVersions($document, $publicOnly)->sortByDesc('version_number')
            : $this->baseQuery($document, $publicOnly)->orderByDesc('version_number')->get();

        foreach ($versions as $version) {
            if (in_array($version->expiryState($evaluationDate), [
                ProductDocumentExpiryState::Expired,
                ProductDocumentExpiryState::NotYetValid,
                ProductDocumentExpiryState::Unknown,
            ], true)) {
                continue;
            }

            return $version;
        }

        return null;
    }

    /**
     * @return Builder<ProductDocumentVersion>
     */
    public function baseQuery(ProductDocument $document, bool $publicOnly = false): Builder
    {
        $query = ProductDocumentVersion::query()
            ->where('company_id', $document->company_id)
            ->where('document_id', $document->getKey())
            ->where('review_status', ProductDocumentReviewStatus::Approved->value)
            ->where('approval_status', ProductDocumentApprovalStatus::Approved->value)
            ->where('file_available', true);

        if ($publicOnly) {
            $query->where('visibility', ProductDocumentVisibility::PassportPublic->value);
        }

        return $query;
    }

    private function loadedEligibleVersions(ProductDocument $document, bool $publicOnly): Collection
    {
        return $document->getRelation('readinessVersions')->filter(fn (ProductDocumentVersion $version): bool => (int) $version->company_id === (int) $document->company_id
            && (int) $version->document_id === (int) $document->getKey()
            && $version->review_status === ProductDocumentReviewStatus::Approved
            && $version->approval_status === ProductDocumentApprovalStatus::Approved
            && $version->file_available
            && (! $publicOnly || $version->visibility === ProductDocumentVisibility::PassportPublic));
    }
}
