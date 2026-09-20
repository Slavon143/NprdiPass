<?php

namespace App\Enums\Catalog;

enum AttributeOptionStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Archived = 'archived';
}
