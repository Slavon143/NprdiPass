<?php

namespace App\Http\Requests\Catalog\Attributes;

use App\Models\Catalog\AttributeDefinition;

class StoreAttributeDefinitionRequest extends DefinitionRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $company = $this->currentCompany();

        return $actor !== null && $company !== null && $actor->can('create', [AttributeDefinition::class, $company]);
    }
}
