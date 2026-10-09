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

    public function label(): string
    {
        return match ($this) {
            self::CompanyRead => 'Company details',
            self::MembersRead => 'Members',
            self::CatalogRead => 'View catalogue',
            self::CatalogWrite => 'Edit catalogue',
            self::CatalogLifecycle => 'Manage product lifecycle',
            self::CatalogMedia => 'Manage catalogue media',
            self::ImportsRead => 'View imports',
            self::ImportsWrite => 'Create and apply imports',
            self::ExportsRead => 'Export catalogue data',
            self::DocumentsRead => 'View documents',
            self::DocumentsWrite => 'Manage documents',
            self::DocumentsMedia => 'Manage document media',
            self::PassportsRead => 'View passports',
            self::PassportsWrite => 'Edit passports',
            self::PassportsPublish => 'Publish passports',
            self::FortnoxRead => 'View Fortnox data',
            self::FortnoxWrite => 'Manage Fortnox sync',
            self::WebhooksManage => 'Manage webhooks',
        };
    }
}
