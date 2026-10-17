<?php

namespace App\Enums\Passports;

enum DppPartyRole: string
{
    case Manufacturer = 'manufacturer';
    case Importer = 'importer';
    case ResponsibleOperator = 'responsible_operator';
}
