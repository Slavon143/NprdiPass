<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\CategoryTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CategoryTemplate */
class CategoryTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'category_uuid' => $this->category->uuid,
            'version' => $this->version,
            'name' => $this->name,
            'status' => $this->status->value,
            'readiness_profile' => [
                'code' => $this->readiness_profile,
                'version' => $this->readiness_profile_version,
                'fingerprint' => $this->readiness_rule_set_fingerprint,
            ],
            'attributes' => $this->whenLoaded('attributes', fn (): array => $this->attributes
                ->sortBy('sort_order')
                ->map(fn ($assignment): array => [
                    'uuid' => $assignment->definition->uuid,
                    'code' => $assignment->definition->code,
                    'name' => $assignment->definition->name,
                    'type' => $assignment->definition->type->value,
                    'scope' => $assignment->definition->scope->value,
                    'requirement' => $assignment->requirement->value,
                    'sort_order' => $assignment->sort_order,
                ])->values()->all()),
            'dpp_defaults' => $this->whenLoaded('dppDefaults', fn (): array => $this->dppDefaults
                ->mapWithKeys(fn ($default): array => [$default->section_key => $default->default_data])
                ->all()),
            'activated_at' => $this->activated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
