<?php

namespace App\Enums;

enum PlatformPermission: string
{
    case PlatformAccess = 'platform.access';
    case CompaniesView = 'platform.companies.view';
    case CompaniesManage = 'platform.companies.manage';
    case UsersView = 'platform.users.view';
    case UsersManage = 'platform.users.manage';
    case AuditView = 'platform.audit.view';
    case Impersonate = 'platform.impersonate';
}
