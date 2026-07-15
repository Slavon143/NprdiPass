<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\AttributeDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttributeDefinition */
class AttributeDefinitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'unit' => $this->unit,
            'required' => $this->required,
            'filterable' => $this->filterable,
            'searchable' => $this->searchable,
            'validation_rules' => $this->validation_rules,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'options' => AttributeOptionResource::collection($this->whenLoaded('options')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
