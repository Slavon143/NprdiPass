<?php

namespace App\Http\Controllers;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NoCompanyController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $resolvedCompany = $request->attributes->get('currentCompany');

        if ($resolvedCompany instanceof Company && $resolvedCompany->status === CompanyStatus::Active) {
            return redirect()->route('dashboard');
        }

        $hasActiveCompany = $user->companies()
            ->where('companies.status', CompanyStatus::Active->value)
            ->exists();

        if ($hasActiveCompany) {
            return redirect()->route('companies.select');
        }

        $hasSuspendedCompany = $user->companies()
            ->where('companies.status', CompanyStatus::Suspended->value)
            ->exists();

        if ($hasSuspendedCompany) {
            return redirect()->route('company.suspended');
        }

        return view('companies.none');
    }
}
