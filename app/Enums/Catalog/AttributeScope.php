<?php

namespace App\Enums\Catalog;

enum AttributeScope: string
{
    case Product = 'product';
    case Variant = 'variant';
    case Both = 'both';
}
