<?php

namespace App\Http\Requests\Catalog\Attributes;

class UpdateAttributeOptionRequest extends OptionRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $definition = $this->definition();
        $option = $definition === null ? null : $this->option($definition);

        return $actor !== null && $option !== null && $actor->can('update', $option);
    }
}
