<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AuthLogin = 'auth.login';
    case AuthLogout = 'auth.logout';
    case AuthLoginFailed = 'auth.login_failed';
    case CompanyCreated = 'company.created';
    case CompanyUpdated = 'company.updated';
    case CompanySwitched = 'company.switched';
    case MemberInvited = 'member.invited';
    case MemberInvitationResent = 'member.invitation_resent';
    case MemberInvitationCancelled = 'member.invitation_cancelled';
    case MemberInvitationAccepted = 'member.invitation_accepted';
    case MemberRoleChanged = 'member.role_changed';
    case MemberRemoved = 'member.removed';
    case CompanyAccessDenied = 'company.access_denied';
    case PlatformRoleAssigned = 'platform.role_assigned';
    case PlatformRoleRemoved = 'platform.role_removed';
    case PlatformAction = 'platform.action';
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';
    case CatalogCategoryCreated = 'catalog.category.created';
    case CatalogCategoryUpdated = 'catalog.category.updated';
    case CatalogCategoryMoved = 'catalog.category.moved';
    case CatalogCategoryReordered = 'catalog.category.reordered';
    case CatalogCategoryArchived = 'catalog.category.archived';
    case CatalogCategoryRestored = 'catalog.category.restored';
    case CatalogProductCreated = 'catalog.product.created';
    case CatalogProductUpdated = 'catalog.product.updated';
    case CatalogProductActivated = 'catalog.product.activated';
    case CatalogProductArchived = 'catalog.product.archived';
    case CatalogProductRestored = 'catalog.product.restored';
    case CatalogVariantCreated = 'catalog.variant.created';
    case CatalogVariantUpdated = 'catalog.variant.updated';
    case CatalogVariantDefaultChanged = 'catalog.variant.default_changed';
    case CatalogVariantArchived = 'catalog.variant.archived';
    case CatalogVariantRestored = 'catalog.variant.restored';
    case CatalogAttributeCreated = 'catalog.attribute.created';
    case CatalogAttributeUpdated = 'catalog.attribute.updated';
    case CatalogAttributeArchived = 'catalog.attribute.archived';
    case CatalogAttributeRestored = 'catalog.attribute.restored';
    case CatalogAttributeOptionCreated = 'catalog.attribute.option.created';
    case CatalogAttributeOptionUpdated = 'catalog.attribute.option.updated';
    case CatalogAttributeOptionArchived = 'catalog.attribute.option.archived';
    case CatalogAttributeOptionRestored = 'catalog.attribute.option.restored';
    case CatalogAttributeOptionsReordered = 'catalog.attribute.options.reordered';
    case CatalogProductAttributesUpdated = 'catalog.product.attributes.updated';
    case CatalogVariantAttributesUpdated = 'catalog.variant.attributes.updated';
    case CatalogMediaUploaded = 'catalog.media.uploaded';
    case CatalogMediaUpdated = 'catalog.media.updated';
    case CatalogMediaPrimaryChanged = 'catalog.media.primary_changed';
    case CatalogMediaReordered = 'catalog.media.reordered';
    case CatalogMediaDeleted = 'catalog.media.deleted';

    public function label(): string
    {
        return match ($this) {
            self::AuthLogin => 'Signed in',
            self::AuthLogout => 'Signed out',
            self::AuthLoginFailed => 'Sign-in failed',
            self::CompanyCreated => 'Company created',
            self::CompanyUpdated => 'Company updated',
            self::CompanySwitched => 'Company switched',
            self::MemberInvited => 'Member invited',
            self::MemberInvitationResent => 'Invitation resent',
            self::MemberInvitationCancelled => 'Invitation cancelled',
            self::MemberInvitationAccepted => 'Invitation accepted',
            self::MemberRoleChanged => 'Member role changed',
            self::MemberRemoved => 'Member removed',
            self::CompanyAccessDenied => 'Company access denied',
            self::PlatformRoleAssigned => 'Platform role assigned',
            self::PlatformRoleRemoved => 'Platform role removed',
            self::PlatformAction => 'Platform action',
            self::ApiTokenCreated => 'API token created',
            self::ApiTokenRevoked => 'API token revoked',
            self::CatalogCategoryCreated => 'Catalog category created',
            self::CatalogCategoryUpdated => 'Catalog category updated',
            self::CatalogCategoryMoved => 'Catalog category moved',
            self::CatalogCategoryReordered => 'Catalog categories reordered',
            self::CatalogCategoryArchived => 'Catalog category archived',
            self::CatalogCategoryRestored => 'Catalog category restored',
            self::CatalogProductCreated => 'Catalog product created',
            self::CatalogProductUpdated => 'Catalog product updated',
            self::CatalogProductActivated => 'Catalog product activated',
            self::CatalogProductArchived => 'Catalog product archived',
            self::CatalogProductRestored => 'Catalog product restored',
            self::CatalogVariantCreated => 'Catalog variant created',
            self::CatalogVariantUpdated => 'Catalog variant updated',
            self::CatalogVariantDefaultChanged => 'Catalog default variant changed',
            self::CatalogVariantArchived => 'Catalog variant archived',
            self::CatalogVariantRestored => 'Catalog variant restored',
            self::CatalogAttributeCreated => 'Catalog attribute created',
            self::CatalogAttributeUpdated => 'Catalog attribute updated',
            self::CatalogAttributeArchived => 'Catalog attribute archived',
            self::CatalogAttributeRestored => 'Catalog attribute restored',
            self::CatalogAttributeOptionCreated => 'Catalog attribute option created',
            self::CatalogAttributeOptionUpdated => 'Catalog attribute option updated',
            self::CatalogAttributeOptionArchived => 'Catalog attribute option archived',
            self::CatalogAttributeOptionRestored => 'Catalog attribute option restored',
            self::CatalogAttributeOptionsReordered => 'Catalog attribute options reordered',
            self::CatalogProductAttributesUpdated => 'Catalog product attributes updated',
            self::CatalogVariantAttributesUpdated => 'Catalog variant attributes updated',
            self::CatalogMediaUploaded => 'Catalog media uploaded',
            self::CatalogMediaUpdated => 'Catalog media updated',
            self::CatalogMediaPrimaryChanged => 'Catalog media primary changed',
            self::CatalogMediaReordered => 'Catalog media reordered',
            self::CatalogMediaDeleted => 'Catalog media deleted',
        };
    }
}
