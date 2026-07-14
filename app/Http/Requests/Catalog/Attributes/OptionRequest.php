<?php

namespace App\Http\Requests\Catalog\Attributes;

abstract class OptionRequest extends AttributeRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['label', 'code'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => trim($value)]);
            }
        }

        $this->merge(['sort_order' => $this->input('sort_order', 0)]);
    }
}
