<?php

namespace App\Http\Resources\Catalog;

use App\Enums\Catalog\AttributeDataType;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\VariantAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VariantAttributeValue */
class VariantAttributeValueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'definition' => $this->whenLoaded('definition', fn (): array => [
                'uuid' => $this->definition->uuid,
                'code' => $this->definition->code,
                'name' => $this->definition->name,
                'type' => $this->definition->type->value,
                'scope' => $this->definition->scope->value,
                'unit' => $this->definition->unit,
            ]),
            'value' => $this->resolveValue(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function resolveValue(): ?array
    {
        $definition = $this->relationLoaded('definition') ? $this->definition : null;

        if ($definition === null) {
            return null;
        }

        $type = $definition->type;

        return match ($type) {
            AttributeDataType::Text => $this->value_text !== null
                ? ['scalar' => $this->value_text]
                : null,
            AttributeDataType::Integer => $this->value_integer !== null
                ? ['scalar' => $this->value_integer]
                : null,
            AttributeDataType::Decimal => $this->value_decimal !== null
                ? ['scalar' => $this->value_decimal]
                : null,
            AttributeDataType::Boolean => ['scalar' => $this->value_boolean],
            AttributeDataType::Date => $this->value_date !== null
                ? ['scalar' => $this->value_date->toDateString()]
                : null,
            AttributeDataType::Select => $this->resolveSelectValue(),
            AttributeDataType::Multiselect => $this->resolveMultiselectValue(),
        };
    }

    /** @return array<string, mixed>|null */
    private function resolveSelectValue(): ?array
    {
        if ($this->relationLoaded('selectedOption') && $this->selectedOption !== null) {
            return [
                'option' => [
                    'id' => $this->selectedOption->id,
                    'code' => $this->selectedOption->code,
                    'label' => $this->selectedOption->label,
                ],
            ];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function resolveMultiselectValue(): ?array
    {
        if (! $this->relationLoaded('selectedOptions')) {
            return null;
        }

        $options = array_map(
            fn (AttributeOption $option): array => [
                'id' => $option->id,
                'code' => $option->code,
                'label' => $option->label,
            ],
            $this->selectedOptions->all(),
        );

        return count($options) > 0
            ? ['options' => $options]
            : null;
    }
}
