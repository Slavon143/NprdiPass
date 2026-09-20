<?php

namespace App\Enums\Catalog;

enum CategoryTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
}
