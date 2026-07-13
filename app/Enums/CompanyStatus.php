<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
