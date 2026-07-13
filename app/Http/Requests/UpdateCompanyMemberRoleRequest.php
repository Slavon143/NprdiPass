<?php

namespace App\Http\Requests;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyMemberRoleRequest extends FormRequest
{
    private ?CompanyMembership $resolvedMembership = null;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('updateRole', $this->membership());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(CompanyRole::class)],
        ];
    }

    public function membership(): CompanyMembership
    {
        if ($this->resolvedMembership !== null) {
            return $this->resolvedMembership;
        }

        $company = $this->attributes->get('currentCompany');
        $membershipId = $this->route('membership');

        abort_unless($company instanceof Company, 404);
        abort_unless(is_string($membershipId), 404);

        return $this->resolvedMembership = $company->memberships()
            ->whereKey($membershipId)
            ->firstOrFail();
    }
}
