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
    case CatalogView = 'catalog.view';
    case CatalogCreate = 'catalog.create';
    case CatalogUpdate = 'catalog.update';
    case CatalogArchive = 'catalog.archive';
    case CatalogPublish = 'catalog.publish';
    case CatalogManageCategories = 'catalog.manage_categories';
    case CatalogManageAttributes = 'catalog.manage_attributes';
    case CatalogManageMedia = 'catalog.manage_media';
}
