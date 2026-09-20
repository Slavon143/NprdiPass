<?php

namespace App\Enums\Catalog;

enum AttributeDefinitionStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Archived = 'archived';
}
