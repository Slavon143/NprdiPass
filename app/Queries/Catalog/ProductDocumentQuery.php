<?php

namespace App\Queries\Catalog;

use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

class ProductDocumentQuery
{
    public function build(Company $company, ?string $uuid = null, array $filters = []): Builder
    {
        $query = ProductDocument::query()
            ->forCompany($company)
            ->with(['currentVersion', 'product', 'creator'])
            ->withCount('versions');

        if ($uuid !== null) {
            $query->where('uuid', $uuid);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->active();
        }

        if (! empty($filters['product_uuid'])) {
            $query->whereHas('product', function (Builder $q) use ($filters): void {
                $q->where('uuid', $filters['product_uuid']);
            });
        }

        if (! empty($filters['document_type'])) {
            $query->whereHas('currentVersion', function (Builder $q) use ($filters): void {
                $q->where('document_type', $filters['document_type']);
            });
        }

        if (! empty($filters['language'])) {
            $query->whereHas('currentVersion', function (Builder $q) use ($filters): void {
                $q->where('language', $filters['language']);
            });
        }

        if (! empty($filters['visibility'])) {
            $query->whereHas('currentVersion', function (Builder $q) use ($filters): void {
                $q->where('visibility', $filters['visibility']);
            });
        }

        if (! empty($filters['review_status'])) {
            $query->whereHas('versions', function (Builder $q) use ($filters): void {
                $q->where('review_status', $filters['review_status']);
            });
        }

        if (! empty($filters['approval_status'])) {
            $query->whereHas('versions', function (Builder $q) use ($filters): void {
                $q->where('approval_status', $filters['approval_status']);
            });
        }

        $expired = ! empty($filters['expired']);
        $expiring = ! empty($filters['expiring']);

        if ($expired || $expiring) {
            $query->whereHas('currentVersion', function (Builder $q) use ($expired, $expiring): void {
                if ($expired) {
                    $q->whereNotNull('expires_at')
                        ->where(function (Builder $dateQuery): void {
                            $dateQuery->where('expires_at', '<', now()->startOfDay())
                                ->orWhere('valid_until', '<', now()->startOfDay());
                        });
                }
                if ($expiring) {
                    $days = (int) config('documents.expiry_warning_days', 30);
                    $now = now()->startOfDay();
                    $deadline = $now->copy()->addDays($days);
                    $q->where(function (Builder $dateQuery) use ($now, $deadline): void {
                        $dateQuery->where(function (Builder $expiresAtQuery) use ($now, $deadline): void {
                            $expiresAtQuery->whereNotNull('expires_at')
                                ->where('expires_at', '>=', $now)
                                ->where('expires_at', '<=', $deadline);
                        })->orWhere(function (Builder $validUntilQuery) use ($now, $deadline): void {
                            $validUntilQuery->whereNotNull('valid_until')
                                ->where('valid_until', '>=', $now)
                                ->where('valid_until', '<=', $deadline);
                        });
                    });
                }
            });
        }

        if (! empty($filters['issuer_search'])) {
            $query->whereHas('currentVersion', function (Builder $q) use ($filters): void {
                $q->where('issuer_name', 'like', '%'.$filters['issuer_search'].'%');
            });
        }

        if (! empty($filters['title_search'])) {
            $query->whereHas('currentVersion', function (Builder $q) use ($filters): void {
                $q->where('title', 'like', '%'.$filters['title_search'].'%');
            });
        }

        if (! empty($filters['sort'])) {
            match ($filters['sort']) {
                'title' => $query->orderBy(
                    ProductDocumentVersion::query()->select('title')
                        ->whereColumn('document_id', 'product_documents.id')
                        ->orderBy('version_number', 'desc')
                        ->limit(1),
                    $filters['direction'] ?? 'asc',
                ),
                'created_at' => $query->orderBy('created_at', $filters['direction'] ?? 'desc'),
                'updated_at' => $query->orderBy('updated_at', $filters['direction'] ?? 'desc'),
                default => $query->orderBy('created_at', 'desc'),
            };
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }
}
