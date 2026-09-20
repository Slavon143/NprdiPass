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
    case ImportsRead = 'imports.read';
    case ImportsWrite = 'imports.write';
    case ExportsRead = 'exports.read';
    case DocumentsRead = 'documents.read';
    case DocumentsWrite = 'documents.write';
    case DocumentsMedia = 'documents.media';
    case PassportsRead = 'passports.read';
    case PassportsWrite = 'passports.write';
    case PassportsPublish = 'passports.publish';
    case FortnoxRead = 'fortnox.read';
    case FortnoxWrite = 'fortnox.write';
    case WebhooksManage = 'webhooks.manage';
}
