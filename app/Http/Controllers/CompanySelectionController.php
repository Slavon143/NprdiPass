<?php

namespace App\Http\Controllers;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanySelectionController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $companies = $user->companies()
            ->where('companies.status', CompanyStatus::Active->value)
            ->orderBy('companies.name')
            ->get();

        if ($companies->count() === 1) {
            $company = $companies->first();

            if ($company instanceof Company) {
                $currentCompany->set($company);

                return redirect()->route('dashboard');
            }
        }

        if ($companies->isEmpty()) {
            $hasSuspendedCompany = $user->companies()
                ->where('companies.status', CompanyStatus::Suspended->value)
                ->exists();

            return redirect()->route($hasSuspendedCompany ? 'company.suspended' : 'companies.none');
        }

        return view('companies.select', [
            'companies' => $companies,
        ]);
    }
}
