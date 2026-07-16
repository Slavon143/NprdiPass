<?php

namespace App\Http\Controllers\Catalog;

use App\Data\Catalog\Audit\CatalogAuditSearchCriteria;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Audit\SearchCatalogAuditRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Queries\Catalog\CatalogAuditQuery;
use App\Tenancy\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CatalogAuditController extends Controller
{
    public function index(SearchCatalogAuditRequest $request, CurrentCompany $currentCompany, CatalogAuditQuery $query): View
    {
        $company = $currentCompany->require();
        $this->authorize('viewAny', [AuditLog::class, $company]);

        $validated = $request->validated();

        $criteria = new CatalogAuditSearchCriteria(
            event: isset($validated['event']) ? AuditEvent::tryFrom($validated['event']) : null,
            actorUuid: $validated['actor'] ?? null,
            resourceType: $validated['resource_type'] ?? null,
            resourceUuid: $validated['resource_uuid'] ?? null,
            requestId: $validated['request_id'] ?? null,
            dateFrom: isset($validated['date_from']) ? CarbonImmutable::parse($validated['date_from']) : null,
            dateTo: isset($validated['date_to']) ? CarbonImmutable::parse($validated['date_to']) : null,
            q: $validated['q'] ?? null,
            perPage: (int) ($validated['per_page'] ?? config('catalog.audit.default_per_page', 50)),
            sort: $validated['sort'] ?? 'created_at',
            direction: $validated['direction'] ?? 'desc',
        );

        $auditLogs = $query->build($criteria, $company->getKey());

        $catalogEvents = array_filter(
            AuditEvent::cases(),
            fn (AuditEvent $e): bool => str_starts_with($e->value, 'catalog.'),
        );

        $userMorphType = (new User)->getMorphClass();
        $actorIds = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->where('causer_type', $userMorphType)
            ->whereNotNull('causer_id')
            ->where('event', 'like', 'catalog.%')
            ->distinct()
            ->pluck('causer_id');
        $actors = User::withTrashed()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email']);

        $resourceTypes = config('catalog.audit.resource_types', []);

        return view()->make('catalog.audit.index', [
            'company' => $company,
            'auditLogs' => $auditLogs,
            'events' => $catalogEvents,
            'actors' => $actors,
            'resourceTypes' => $resourceTypes,
            'perPageOptions' => config('catalog.audit.per_page_options', [25, 50, 100]),
        ]);
    }

    public function show(string $auditEvent, CurrentCompany $currentCompany): View|RedirectResponse
    {
        $company = $currentCompany->require();

        $auditLog = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->where('log_name', 'tenant')
            ->where('event', 'like', 'catalog.%')
            ->findOrFail($auditEvent);

        $this->authorize('view', [AuditLog::class, $auditLog, $company]);

        $changes = $auditLog->getProperty('changes', []);
        $properties = $auditLog->attributesToArray()['properties'] ?? [];
        $safeProperties = is_array($properties) ? $properties : json_decode((string) $properties, true) ?? [];

        $safeProperties = array_diff_key(
            is_array($safeProperties) ? $safeProperties : [],
            array_flip(['actor_email', 'storage_path', 'token', 'password']),
        );

        return view()->make('catalog.audit.show', [
            'company' => $company,
            'auditLog' => $auditLog,
            'changes' => $changes,
            'safeProperties' => $safeProperties,
        ]);
    }
}
