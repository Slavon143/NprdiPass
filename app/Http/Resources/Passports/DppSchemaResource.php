<?php

namespace App\Http\Resources\Passports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DppSchemaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sections = [];

        foreach ($this->resource as $section) {
            $fields = [];

            foreach ($section->fields as $field) {
                $fields[] = [
                    'key' => $field->key,
                    'type' => $field->type->value,
                    'translatable' => $field->translatable,
                    'nullable' => $field->nullable,
                    'max_length' => $field->maxLength,
                    'max_items' => $field->maxItems,
                    'min' => $field->min,
                    'max' => $field->max,
                ];
            }

            $sections[] = [
                'key' => $section->key->value,
                'label' => $section->key->label(),
                'core' => $section->core,
                'translatable' => $section->translatable,
                'fields' => $fields,
            ];
        }

        return [
            'sections' => $sections,
        ];
    }
}
