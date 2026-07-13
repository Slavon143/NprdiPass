<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Requests\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('viewAny', [AuditLog::class, $company]);
        $validated = $request->validated();
        $userMorphType = (new User)->getMorphClass();

        $query = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->where('log_name', 'tenant')
            ->latest('created_at')
            ->latest('id');

        $event = $validated['event'] ?? null;

        if (is_string($event)) {
            $query->where('event', $event);
        }

        $actorUuid = $validated['actor'] ?? null;

        if (is_string($actorUuid)) {
            $actor = User::withTrashed()->where('uuid', $actorUuid)->firstOrFail();
            $belongsToCompany = $company->memberships()
                ->where('user_id', $actor->getKey())
                ->exists();
            $hasTenantEvent = AuditLog::query()
                ->where('company_id', $company->getKey())
                ->where('causer_type', $userMorphType)
                ->where('causer_id', $actor->getKey())
                ->exists();

            abort_unless($belongsToCompany || $hasTenantEvent, 404);

            $query->where('causer_type', $userMorphType)
                ->where('causer_id', $actor->getKey());
        }

        $dateFrom = $validated['date_from'] ?? null;

        if (is_string($dateFrom)) {
            $query->where('created_at', '>=', $dateFrom.' 00:00:00');
        }

        $dateTo = $validated['date_to'] ?? null;

        if (is_string($dateTo)) {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        $actorIds = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->where('causer_type', $userMorphType)
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id');
        $actors = User::withTrashed()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email']);

        return view()->make('audit.index', [
            'company' => $company,
            'auditLogs' => $query->paginate(50)->withQueryString(),
            'events' => AuditEvent::cases(),
            'actors' => $actors,
        ]);
    }
}
