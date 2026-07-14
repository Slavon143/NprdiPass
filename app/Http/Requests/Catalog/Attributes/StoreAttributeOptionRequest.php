<?php

namespace App\Http\Requests\Catalog\Attributes;

use App\Models\Catalog\AttributeOption;

class StoreAttributeOptionRequest extends OptionRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $definition = $this->definition();

        return $actor !== null && $definition !== null && $actor->can('create', [AttributeOption::class, $definition]);
    }
}
