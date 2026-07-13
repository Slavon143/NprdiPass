<?php

namespace App\Http\Controllers;

use App\Actions\Companies\UpdateCompany;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
    public function edit(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('view', $company);

        return view()->make('settings.company.edit', [
            'company' => $company,
            'canUpdate' => $request->user()?->can('update', $company) === true,
        ]);
    }

    public function update(
        UpdateCompanyRequest $request,
        CurrentCompany $currentCompany,
        UpdateCompany $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $action->execute($user, $currentCompany->require(), $request->validated());

        return redirect()
            ->route('settings.company.edit')
            ->with('success', 'Company settings updated.');
    }
}
