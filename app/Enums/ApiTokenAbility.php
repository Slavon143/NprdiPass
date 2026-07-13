<?php

namespace App\Enums;

enum ApiTokenAbility: string
{
    case CompanyRead = 'company.read';
    case MembersRead = 'members.read';
}
