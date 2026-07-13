<?php

namespace App\Http\Requests;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Security\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->attributes->get('currentCompany');

        return $user instanceof User
            && $company instanceof Company
            && $user->can('create', [CompanyInvitation::class, $company]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $roleRules = ['required', Rule::enum(CompanyRole::class)];
        $currentMembership = $this->attributes->get('currentMembership');

        if (
            $currentMembership instanceof CompanyMembership
            && $currentMembership->role === CompanyRole::Admin
        ) {
            $roleRules[] = Rule::notIn([CompanyRole::Owner->value]);
        }

        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => $roleRules,
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => app(EmailNormalizer::class)->normalize(is_string($email) ? $email : ''),
        ]);
    }
}
