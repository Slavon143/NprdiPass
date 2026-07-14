<?php

namespace App\Enums\Catalog;

enum CategoryStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
