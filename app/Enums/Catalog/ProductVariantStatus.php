<?php

namespace App\Enums\Catalog;

enum ProductVariantStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
