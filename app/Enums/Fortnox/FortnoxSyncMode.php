<?php

namespace App\Enums\Fortnox;

enum FortnoxSyncMode: string
{
    case Incremental = 'incremental';
    case Full = 'full';
}
