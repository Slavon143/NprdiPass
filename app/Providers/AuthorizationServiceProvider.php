<?php

namespace App\Providers;

use App\Authorization\CompanyAuthorizer;
use App\Authorization\CompanyPermissionGate;
use App\Authorization\CompanyPermissionMatrix;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Policies\CompanyInvitationPolicy;
use App\Policies\CompanyMemberPolicy;
use App\Policies\CompanyPolicy;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyPermissionMatrix::class);
        $this->app->bind(CompanyAuthorizer::class, CompanyAuthorizer::class);
    }

    public function boot(Gate $gate): void
    {
        $gate->policy(Company::class, CompanyPolicy::class);
        $gate->policy(CompanyMembership::class, CompanyMemberPolicy::class);
        $gate->policy(CompanyInvitation::class, CompanyInvitationPolicy::class);

        $gate->define(CompanyPermission::CompanyView, CompanyPermissionGate::class.'@companyView');
        $gate->define(CompanyPermission::CompanyUpdate, CompanyPermissionGate::class.'@companyUpdate');
        $gate->define(CompanyPermission::MembersView, CompanyPermissionGate::class.'@membersView');
        $gate->define(CompanyPermission::MembersInvite, CompanyPermissionGate::class.'@membersInvite');
        $gate->define(CompanyPermission::MembersUpdateRole, CompanyPermissionGate::class.'@membersUpdateRole');
        $gate->define(CompanyPermission::MembersRemove, CompanyPermissionGate::class.'@membersRemove');
        $gate->define(CompanyPermission::AuditView, CompanyPermissionGate::class.'@auditView');
        $gate->define(CompanyPermission::ApiTokensView, CompanyPermissionGate::class.'@apiTokensView');
        $gate->define(CompanyPermission::ApiTokensCreate, CompanyPermissionGate::class.'@apiTokensCreate');
        $gate->define(CompanyPermission::ApiTokensRevoke, CompanyPermissionGate::class.'@apiTokensRevoke');
    }
}
