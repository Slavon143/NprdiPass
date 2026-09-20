<?php

namespace App\Enums\Imports;

enum ImportMode: string
{
    case Create = 'create';
    case Update = 'update';
    case Upsert = 'upsert';
}
