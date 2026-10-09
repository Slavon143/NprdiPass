<?php

namespace App\Services\Billing;

use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class CommercialUsageAdmission
{
    private const METRICS = ['imports' => 'imports.jobs.max', 'api' => 'api.requests.max'];

    public function consume(Company $company, string $metric, string $operationId): void
    {
        if (DB::transactionLevel() === 0 || ! isset(self::METRICS[$metric]) || ! Str::isUuid($operationId)) {
            throw new LogicException('Usage admission requires a transaction, known metric and server operation UUID.');
        }
        // A caller may catch admission failures inside its transaction. The
        // savepoint still removes every rejected event and bucket reservation.
        DB::transaction(fn () => $this->consumeLocked($company, $metric, $operationId));
    }

    private function consumeLocked(Company $company, string $metric, string $operationId): void
    {
        $company = Company::query()->whereKey($company->getKey())->sharedLock()->firstOrFail();
        if ($company->commercial_model_version !== 1) {
            return;
        }
        app(CommercialAccessPolicy::class)->assertFeature($company, $metric === 'api' ? 'api.enabled' : 'imports.enabled');
        $subscription = CompanySubscription::query()->forCompany($company)->sharedLock()->firstOrFail();
        $window = app(CommercialUsageWindow::class)->resolve($subscription, now()->toImmutable()->utc());
        $limit = app(EntitlementService::class)->limitForMutation($company, self::METRICS[$metric], sharedCompany: true);
        if ($limit === null || $limit <= 0) {
            throw ValidationException::withMessages(['entitlement' => 'The '.self::METRICS[$metric].' allowance is unavailable.']);
        }
        $identity = ['company_id' => $company->getKey(), 'metric' => $metric, 'window_start' => $window['start']];
        DB::table('commercial_usage_buckets')->insertOrIgnore([
            ...$identity, 'window_end' => $window['end'], 'used' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bucket = DB::table('commercial_usage_buckets')->where($identity)->lockForUpdate()->first();
        if ($bucket === null) {
            throw new LogicException('Usage bucket could not be locked.');
        }
        $operation = ['company_id' => $company->getKey(), 'metric' => $metric, 'operation_id' => $operationId];
        // Insert, rather than locking an absent key: RR gap locks can cover
        // another tenant. The unique operation index serializes duplicate claims.
        $inserted = DB::table('commercial_usage_events')->insertOrIgnore([
            ...$operation, 'bucket_id' => $bucket->id, 'units' => 1, 'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return;
        }
        if ((int) $bucket->used >= $limit) {
            throw ValidationException::withMessages(['entitlement' => 'The '.self::METRICS[$metric].' limit has been reached for the current period.']);
        }
        DB::table('commercial_usage_buckets')->where('id', $bucket->id)->increment('used', 1, ['updated_at' => now()]);
    }
}
