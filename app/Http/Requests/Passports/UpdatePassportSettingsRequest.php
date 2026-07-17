<?php

namespace App\Http\Requests\Passports;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePassportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'settings.required' => 'Settings are required.',
            'settings.array' => 'Settings must be an array.',
            'expected_revision.required' => 'Expected revision is required.',
            'expected_revision.integer' => 'Expected revision must be an integer.',
            'expected_revision.min' => 'Expected revision must be at least 1.',
        ];
    }
}
