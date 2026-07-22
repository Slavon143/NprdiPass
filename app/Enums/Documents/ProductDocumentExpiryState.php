<?php

namespace App\Enums\Documents;

enum ProductDocumentExpiryState: string
{
    case NotApplicable = 'not_applicable';
    case NotYetValid = 'not_yet_valid';
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
