<?php

namespace App\Enums\Documents;

enum ProductDocumentVisibility: string
{
    case Internal = 'internal';
    case PassportPublic = 'passport_public';
}
