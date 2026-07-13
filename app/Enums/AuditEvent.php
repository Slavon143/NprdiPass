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
        };
    }
}
