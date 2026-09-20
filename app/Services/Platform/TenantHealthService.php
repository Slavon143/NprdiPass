<?php

namespace App\Services\Platform;

use App\Enums\Jobs\JobStatusState;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Jobs\JobStatus;
use App\Models\Notifications\Notification;
use App\Models\Platform\PlatformIncident;

class TenantHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Company $company): array
    {
        $failedJobs = JobStatus::query()
            ->forCompany($company)
            ->where('status', JobStatusState::Failed->value)
            ->count();
        $openIncidents = PlatformIncident::query()
            ->where('company_id', $company->getKey())
            ->where('status', PlatformIncidentStatus::Open->value)
            ->count();

        $status = match (true) {
            $openIncidents > 0 => 'incident',
            $failedJobs > 0 => 'degraded',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'members' => CompanyMembership::query()->where('company_id', $company->getKey())->count(),
            'products' => Product::query()->forCompany($company)->count(),
            'failed_jobs' => $failedJobs,
            'pending_jobs' => JobStatus::query()
                ->forCompany($company)
                ->whereIn('status', [JobStatusState::Pending->value, JobStatusState::Running->value, JobStatusState::CancelRequested->value])
                ->count(),
            'unread_notifications' => Notification::query()
                ->forCompany($company)
                ->whereNull('read_at')
                ->whereNull('dismissed_at')
                ->count(),
            'open_incidents' => $openIncidents,
        ];
    }
}
