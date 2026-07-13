<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->attributes->get('currentCompany');

        return $user instanceof User
            && $company instanceof Company
            && $user->can('update', $company);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'organization_number' => ['nullable', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
            'billing_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizedString('name'),
            'legal_name' => $this->normalizedNullableString('legal_name'),
            'organization_number' => $this->normalizedNullableString('organization_number'),
            'country_code' => strtoupper($this->normalizedString('country_code')),
            'billing_email' => $this->normalizedEmail(),
        ]);
    }

    private function normalizedString(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : '';
    }

    private function normalizedNullableString(string $key): ?string
    {
        $value = $this->normalizedString($key);

        return $value === '' ? null : $value;
    }

    private function normalizedEmail(): ?string
    {
        $value = $this->normalizedNullableString('billing_email');

        return $value === null ? null : strtolower($value);
    }
}
