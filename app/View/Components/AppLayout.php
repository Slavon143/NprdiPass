<?php

namespace App\View\Components;

use App\Models\Company;
use App\Models\CompanyMembership;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    public readonly ?Company $currentCompany;

    public readonly ?CompanyMembership $currentMembership;

    public function __construct(Request $request)
    {
        $company = $request->attributes->get('currentCompany');
        $membership = $request->attributes->get('currentMembership');

        $this->currentCompany = $company instanceof Company ? $company : null;
        $this->currentMembership = $membership instanceof CompanyMembership ? $membership : null;
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
