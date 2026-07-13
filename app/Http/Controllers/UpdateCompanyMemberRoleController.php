<?php

namespace App\Http\Controllers;

use App\Actions\Companies\ChangeCompanyMemberRole;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\CompanyRole;
use App\Http\Requests\UpdateCompanyMemberRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UpdateCompanyMemberRoleController extends Controller
{
    public function __invoke(
        UpdateCompanyMemberRoleRequest $request,
        ChangeCompanyMemberRole $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $action->execute(
                $user,
                $request->membership(),
                CompanyRole::from($request->string('role')->toString()),
            );
        } catch (LastCompanyOwnerCannotBeRemoved) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'At least one owner is required.']);
        }

        return back()->with('success', 'Member role updated.');
    }
}
