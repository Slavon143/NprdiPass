<?php

namespace App\Enums;

enum CompanyPermission: string
{
    case CompanyView = 'company.view';
    case CompanyUpdate = 'company.update';
    case MembersView = 'members.view';
    case MembersInvite = 'members.invite';
    case MembersUpdateRole = 'members.update_role';
    case MembersRemove = 'members.remove';
    case AuditView = 'audit.view';
    case ApiTokensView = 'api_tokens.view';
    case ApiTokensCreate = 'api_tokens.create';
    case ApiTokensRevoke = 'api_tokens.revoke';
}
