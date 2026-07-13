<?php

namespace App\Actions\Companies;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateCompany
{
    private const ALLOWED_FIELDS = [
        'name',
        'legal_name',
        'organization_number',
        'country_code',
        'billing_email',
    ];

    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Company $company, array $data): Company
    {
        if ($company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize(
            $actor,
            $company,
            CompanyPermission::CompanyUpdate,
        );

        DB::transaction(function () use ($actor, $company, $data): void {
            $company->fill(array_intersect_key($data, array_flip(self::ALLOWED_FIELDS)));
            $changes = [];

            foreach (self::ALLOWED_FIELDS as $field) {
                if (! $company->isDirty($field)) {
                    continue;
                }

                $changes[$field] = [
                    'old' => $company->getOriginal($field),
                    'new' => $company->getAttribute($field),
                ];
            }

            $company->save();

            if ($changes !== []) {
                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CompanyUpdated,
                    $actor,
                    $company,
                    ['changes' => $changes],
                );
            }
        });

        return $company->refresh();
    }
}
