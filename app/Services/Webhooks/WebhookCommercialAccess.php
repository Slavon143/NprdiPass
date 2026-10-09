<?php

namespace App\Services\Webhooks;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Billing\CommercialAccessPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class WebhookCommercialAccess
{
    public function allowsDelivery(int $companyId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Webhook admission requires a transaction.');
        }
        $company = Company::query()->whereKey($companyId)->sharedLock()->first();
        if ($company === null || $company->status !== CompanyStatus::Active) {
            return false;
        }
        try {
            app(CommercialAccessPolicy::class)->assertFeature($company, 'webhooks.enabled');
        } catch (ValidationException) {
            return false;
        }

        return true;
    }
}
