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

class ProductDocumentCurrentVersionResolver
{
    public function resolve(
        ProductDocument $document,
        bool $publicOnly = false,
        ?CarbonImmutable $evaluationDate = null,
    ): ?ProductDocumentVersion {
        if (! $document->isActive()) {
            return null;
        }

        $versions = $this->baseQuery($document, $publicOnly)
            ->orderByDesc('version_number')
            ->get();

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
}
