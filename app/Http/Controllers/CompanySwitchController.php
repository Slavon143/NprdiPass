<?php

namespace App\Http\Controllers;

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
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $isMember = $user->companies()
            ->where('companies.id', $company->getKey())
            ->exists();

        abort_unless($isMember, 403);
        abort_unless($company->status === CompanyStatus::Active, 403);

        $currentCompany->set($company);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
