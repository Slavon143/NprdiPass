<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(
        Request $request,
        Company $company,
        CurrentCompany $currentCompany,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $isMember = $user->companies()
            ->where('companies.id', $company->getKey())
            ->exists();

        if (! $isMember) {
            $auditLogger->logPlatform(
                AuditEvent::CompanyAccessDenied,
                $user,
                $company,
                [
                    'requested_company_uuid' => $company->getAttribute('uuid'),
                    'reason' => 'membership_required',
                ],
            );
            abort(403);
        }

        if ($company->status !== CompanyStatus::Active) {
            $auditLogger->logPlatform(
                AuditEvent::CompanyAccessDenied,
                $user,
                $company,
                [
                    'requested_company_uuid' => $company->getAttribute('uuid'),
                    'reason' => 'company_inactive',
                ],
            );
            abort(403);
        }

        $fromCompany = $currentCompany->get();

        $currentCompany->set($company);
        $request->session()->regenerate();

        $auditLogger->logTenant(
            $company,
            AuditEvent::CompanySwitched,
            $user,
            $company,
            [
                'from_company_uuid' => $fromCompany?->getAttribute('uuid'),
                'from_company_name' => $fromCompany?->getAttribute('name'),
                'to_company_uuid' => $company->getAttribute('uuid'),
            ],
        );

        return redirect()->route('dashboard');
    }
}
