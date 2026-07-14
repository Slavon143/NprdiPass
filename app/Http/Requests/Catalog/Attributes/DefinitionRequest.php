<?php

namespace App\Http\Requests\Catalog\Attributes;

abstract class DefinitionRequest extends AttributeRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:text,integer,decimal,boolean,date,select,multiselect'],
            'scope' => ['required', 'string', 'in:product,variant,both'],
            'unit' => ['nullable', 'string', 'max:50'],
            'required' => ['required', 'boolean'],
            'filterable' => ['required', 'boolean'],
            'searchable' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'validation_rules' => ['nullable', 'array'],
            'validation_rules.min_length' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'validation_rules.max_length' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'validation_rules.min' => ['nullable'],
            'validation_rules.max' => ['nullable'],
            'validation_rules.min_date' => ['nullable', 'date_format:Y-m-d'],
            'validation_rules.max_date' => ['nullable', 'date_format:Y-m-d'],
            'validation_rules.min_selections' => ['nullable', 'integer', 'min:0', 'max:200'],
            'validation_rules.max_selections' => ['nullable', 'integer', 'min:0', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trim('name'),
            'code' => $this->trim('code'),
            'description' => $this->nullableTrim('description'),
            'unit' => $this->nullableTrim('unit'),
            'required' => $this->boolean('required'),
            'filterable' => $this->boolean('filterable'),
            'searchable' => $this->boolean('searchable'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    private function trim(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    private function nullableTrim(string $key): mixed
    {
        $value = $this->trim($key);

        return $value === '' ? null : $value;
    }
}
