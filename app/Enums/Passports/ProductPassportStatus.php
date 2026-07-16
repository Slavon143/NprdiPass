<?php

namespace App\Enums\Passports;

enum ProductPassportStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
}
