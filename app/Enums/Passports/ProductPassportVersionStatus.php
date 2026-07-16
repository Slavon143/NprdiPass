<?php

namespace App\Enums\Passports;

enum ProductPassportVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';
}
