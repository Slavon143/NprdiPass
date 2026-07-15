<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\AttributeOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttributeOption */
class AttributeOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
