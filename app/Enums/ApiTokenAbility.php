<?php

namespace App\Enums;

enum ApiTokenAbility: string
{
    case CompanyRead = 'company.read';
    case MembersRead = 'members.read';
    case CatalogRead = 'catalog.read';
    case CatalogWrite = 'catalog.write';
    case CatalogLifecycle = 'catalog.lifecycle';
    case CatalogMedia = 'catalog.media';
}
