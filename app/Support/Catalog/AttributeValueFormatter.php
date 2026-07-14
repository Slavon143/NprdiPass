<?php

namespace App\Support\Catalog;

use App\Enums\Catalog\AttributeDataType;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\VariantAttributeValue;

class AttributeValueFormatter
{
    public function format(ProductAttributeValue|VariantAttributeValue $value): string
    {
        $definition = $value->definition;
        $formatted = match ($definition->type) {
            AttributeDataType::Text => (string) $value->value_text,
            AttributeDataType::Integer => (string) $value->value_integer,
            AttributeDataType::Decimal => rtrim(rtrim((string) $value->value_decimal, '0'), '.'),
            AttributeDataType::Boolean => $value->value_boolean ? 'Yes' : 'No',
            AttributeDataType::Date => $value->value_date?->format('Y-m-d') ?? '',
            AttributeDataType::Select => (string) $value->selectedOption?->label,
            AttributeDataType::Multiselect => $value->selectedOptions->pluck('label')->implode(', '),
        };

        return $formatted !== '' && $definition->unit !== null
            ? $formatted.' '.$definition->unit
            : $formatted;
    }
}
