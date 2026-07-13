<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('view', $company);

        $membership = request()->attributes->get('currentMembership');
        abort_unless($membership instanceof CompanyMembership, 403);

        return view()->make('dashboard.index', [
            'company' => $company,
            'membership' => $membership,
            'memberCount' => $company->memberships()->count(),
        ]);
    }
}
