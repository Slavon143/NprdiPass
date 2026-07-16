<?php

namespace App\Queries\Catalog;

use App\Data\Catalog\Audit\CatalogAuditSearchCriteria;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CatalogAuditQuery
{
    /** @var string[] */
    private array $catalogEventPrefixes = ['catalog.'];

    public function build(CatalogAuditSearchCriteria $criteria, int $companyId): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->where('company_id', $companyId)
            ->where('log_name', 'tenant')
            ->latest('created_at')
            ->latest('id');

        $this->applyCatalogEventFilter($query);
        $this->applyEvent($query, $criteria);
        $this->applyActor($query, $criteria, $companyId);
        $this->applyResourceType($query, $criteria);
        $this->applyResourceUuid($query, $criteria);
        $this->applyRequestId($query, $criteria);
        $this->applyDateRange($query, $criteria);
        $this->applyKeywordSearch($query, $criteria);

        return $query->paginate($criteria->perPage)->withQueryString();
    }

    private function applyCatalogEventFilter(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            foreach ($this->catalogEventPrefixes as $prefix) {
                $q->orWhere('event', 'like', $prefix.'%');
            }
        });
    }

    private function applyEvent(Builder $query, CatalogAuditSearchCriteria $criteria): void
    {
        if ($criteria->event !== null) {
            $query->where('event', $criteria->event->value);
        }
    }

    private function applyActor(Builder $query, CatalogAuditSearchCriteria $criteria, int $companyId): void
    {
        if ($criteria->actorUuid === null) {
            return;
        }

        $actor = User::withTrashed()->where('uuid', $criteria->actorUuid)->first();

        if ($actor === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $userMorphType = (new User)->getMorphClass();

        $query->where('causer_type', $userMorphType)
            ->where('causer_id', $actor->getKey());
    }

    private function applyResourceType(Builder $query, CatalogAuditSearchCriteria $criteria): void
    {
        if ($criteria->resourceType === null) {
            return;
        }

        $query->where(function (Builder $q) use ($criteria): void {
            $q->orWhere('subject_type', 'like', '%'.ucfirst($criteria->resourceType).'%')
                ->orWhere('event', 'like', 'catalog.'.$criteria->resourceType.'.%');
        });
    }

    private function applyResourceUuid(Builder $query, CatalogAuditSearchCriteria $criteria): void
    {
        if ($criteria->resourceUuid === null) {
            return;
        }

        $query->where(function (Builder $q) use ($criteria): void {
            foreach (['category_uuid', 'product_uuid', 'variant_uuid',
                'attribute_definition_uuid', 'option_uuid', 'media_uuid',
                'resource_uuid', 'old_parent_uuid', 'new_parent_uuid'] as $field) {
                $q->orWhere('properties->'.$field, $criteria->resourceUuid);
            }
        });
    }

    private function applyRequestId(Builder $query, CatalogAuditSearchCriteria $criteria): void
    {
        if ($criteria->requestId !== null) {
            $query->where('request_id', $criteria->requestId);
        }
    }

    private function applyDateRange(Builder $query, CatalogAuditSearchCriteria $criteria): void
    {
        if ($criteria->dateFrom !== null) {
            $query->where('created_at', '>=', $criteria->dateFrom->startOfDay());
        }

        if ($criteria->dateTo !== null) {
            $query->where('created_at', '<=', $criteria->dateTo->endOfDay());
        }
    }

    private function applyKeywordSearch(Builder $query, CatalogAuditSearchCriteria $criteria): void
    {
        if ($criteria->q === null || $criteria->q === '') {
            return;
        }

        $term = $criteria->q;

        $query->where(function (Builder $q) use ($term): void {
            $q->where('event', 'like', '%'.$term.'%')
                ->orWhere('request_id', $term);
        });
    }
}
