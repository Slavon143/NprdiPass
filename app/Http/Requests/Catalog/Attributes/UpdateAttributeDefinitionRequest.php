<?php

namespace App\Http\Requests\Catalog\Attributes;

class UpdateAttributeDefinitionRequest extends DefinitionRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $definition = $this->definition();

        return $actor !== null && $definition !== null && $actor->can('update', $definition);
    }
}
