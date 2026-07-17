<?php

namespace App\Enums\Passports\Readiness;

enum ReadinessRuleGroup: string
{
    case Catalog = 'catalog';
    case Passport = 'passport';
    case Identity = 'identity';
    case Manufacturer = 'manufacturer';
    case Safety = 'safety';
    case Recycling = 'recycling';
    case Media = 'media';
    case Documents = 'documents';
    case Certificates = 'certificates';
    case Environmental = 'environmental';
    case Support = 'support';
    case Technical = 'technical';
}
