<?php

namespace App\Enums\Documents;

enum ProductDocumentStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
