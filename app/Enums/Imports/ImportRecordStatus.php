<?php

namespace App\Enums\Imports;

enum ImportRecordStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Applied = 'applied';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
