<?php

namespace App\Http\Requests\Passports;

use Illuminate\Foundation\Http\FormRequest;

class RestorePassportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
