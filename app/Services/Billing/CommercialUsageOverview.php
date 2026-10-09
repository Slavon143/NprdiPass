<?php

namespace App\Services\Billing;

use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Services\Storage\TenantStorageLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialUsageOverview
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantStorageLedger $storage) {}

    /** @return array{meters: list<array{key: string, label: string, used: ?int, limit: ?int, near_limit: bool}>, window_end: ?CarbonImmutable} */
    public function forCompany(Company $company, ?CompanySubscription $subscription): array
    {
        $at = now()->toImmutable()->utc();
        $window = null;
        if ($subscription !== null) {
            try {
                $window = app(CommercialUsageWindow::class)->resolve($subscription, $at);
            } catch (ValidationException) {
                // An expired/unconfirmed period is unknown, never a reset to zero.
            }
        }
        $usage = $window === null ? [] : DB::table('commercial_usage_buckets')->where('company_id', $company->id)
            ->where('window_start', $window['start'])->whereIn('metric', ['imports', 'api'])->pluck('used', 'metric')->all();
        $seats = CompanyMembership::query()->where('company_id', $company->id)->count()
            + CompanyInvitation::query()->where('company_id', $company->id)->whereNull('accepted_at')->whereNull('cancelled_at')
                ->where('expires_at', '>', $at)->count();
        $definitions = [
            ['seats', 'Seats, including pending invitations', $seats, 'members.max'],
            ['storage', 'Retained storage', $this->storage->usedBytes($company), 'storage.bytes.max'],
            ['imports', 'Imports this period', $window === null ? null : (int) ($usage['imports'] ?? 0), 'imports.jobs.max'],
            ['api', 'API requests this period', $window === null ? null : (int) ($usage['api'] ?? 0), 'api.requests.max'],
        ];
        $meters = [];
        foreach ($definitions as [$key, $label, $used, $entitlement]) {
            $limit = $this->entitlements->limit($company, $entitlement);
            $meters[] = ['key' => $key, 'label' => $label, 'used' => $used, 'limit' => $limit,
                'near_limit' => $used !== null && $limit !== null && $limit > 0 && $used >= $limit - intdiv($limit, 10)];
        }

        return ['meters' => $meters, 'window_end' => $window['end'] ?? null];
    }
}
