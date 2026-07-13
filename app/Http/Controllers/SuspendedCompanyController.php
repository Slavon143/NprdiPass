<?php

namespace App\Http\Controllers;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuspendedCompanyController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $currentCompany = $request->attributes->get('currentCompany');

        if ($currentCompany instanceof Company) {
            if ($currentCompany->status === CompanyStatus::Active) {
                return redirect()->route('dashboard');
            }

            abort_if($currentCompany->status === CompanyStatus::Archived, 403);
        }

        $suspendedCompanies = $user->companies()
            ->where('companies.status', CompanyStatus::Suspended->value)
            ->orderBy('companies.name')
            ->get();

        if ($suspendedCompanies->isEmpty()) {
            $hasActiveCompany = $user->companies()
                ->where('companies.status', CompanyStatus::Active->value)
                ->exists();

            return redirect()->route($hasActiveCompany ? 'companies.select' : 'companies.none');
        }

        $availableCompanies = $user->companies()
            ->where('companies.status', CompanyStatus::Active->value)
            ->orderBy('companies.name')
            ->get();

        return view('companies.suspended', [
            'currentCompany' => $currentCompany instanceof Company ? $currentCompany : null,
            'suspendedCompanies' => $suspendedCompanies,
            'availableCompanies' => $availableCompanies,
        ]);
    }
}
