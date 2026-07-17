<?php

namespace App\Http\Requests\Passports;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePassportSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'section_payload' => ['required', 'array'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'locale' => ['sometimes', 'string', 'size:2'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'section_payload.required' => 'Section payload is required.',
            'section_payload.array' => 'Section payload must be an array.',
            'expected_revision.required' => 'Expected revision is required.',
            'expected_revision.integer' => 'Expected revision must be an integer.',
            'expected_revision.min' => 'Expected revision must be at least 1.',
            'locale.size' => 'Locale must be a 2-letter code.',
        ];
    }
}
