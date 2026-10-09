<?php

namespace App\Http\Controllers;

use App\Actions\Companies\CreateCompanyForOwner;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreateCompanyController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user() instanceof User, 401);

        return view('companies.create');
    }

    public function store(Request $request, CreateCompanyForOwner $action, CurrentCompany $currentCompany): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization_number' => ['nullable', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);
        $company = $action->execute($user, $validated['name'], $validated['organization_number'] ?? null, $validated['country_code']);
        $currentCompany->set($company);

        return redirect()->route('onboarding.index')->with('success', 'Organization created. Your trial is active.');
    }
}
