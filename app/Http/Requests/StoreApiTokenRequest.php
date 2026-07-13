<?php

namespace App\Http\Requests;

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->attributes->get('currentCompany');

        return $user instanceof User
            && $company instanceof Company
            && $user->can(CompanyPermission::ApiTokensCreate->value, $company);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $expirations = ['30_days', '90_days', '1_year'];

        if (config('api.allow_non_expiring_tokens', false)) {
            $expirations[] = 'never';
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'distinct', new Enum(ApiTokenAbility::class)],
            'expiration' => ['required', Rule::in($expirations)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $this->merge(['name' => is_string($name) ? trim($name) : $name]);
    }
}
