<?php

namespace App\Enums\Catalog;

enum CategoryTemplateAttributeRequirement: string
{
    case Required = 'required';
    case Recommended = 'recommended';
}
