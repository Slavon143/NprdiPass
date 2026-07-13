<?php

namespace App\View\Components;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    public readonly ?Company $currentCompany;

    public readonly ?CompanyMembership $currentMembership;

    /** @var Collection<int, Company> */
    public readonly Collection $availableCompanies;

    public function __construct(Request $request)
    {
        $company = $request->attributes->get('currentCompany');
        $membership = $request->attributes->get('currentMembership');

        $this->currentCompany = $company instanceof Company ? $company : null;
        $this->currentMembership = $membership instanceof CompanyMembership ? $membership : null;

        $user = $request->user();
        $this->availableCompanies = $user instanceof User
            ? $user->companies()
                ->where('companies.status', CompanyStatus::Active->value)
                ->orderBy('companies.name')
                ->get()
            : new Collection;
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
