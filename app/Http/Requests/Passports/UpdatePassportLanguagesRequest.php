<?php

namespace App\Http\Requests\Passports;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePassportLanguagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'default_language' => ['required', 'string', 'size:2'],
            'enabled_languages' => ['required', 'array', 'min:1'],
            'enabled_languages.*' => ['string', 'size:2', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'default_language.required' => 'Default language is required.',
            'default_language.size' => 'Default language must be a 2-letter code.',
            'enabled_languages.required' => 'Enabled languages are required.',
            'enabled_languages.min' => 'At least one language must be enabled.',
            'enabled_languages.*.size' => 'Each language code must be 2 letters.',
            'enabled_languages.*.distinct' => 'Duplicate language codes are not allowed.',
        ];
    }
}
