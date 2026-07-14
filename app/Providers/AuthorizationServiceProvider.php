<?php

namespace App\Providers;

use App\Authorization\CompanyAuthorizer;
use App\Authorization\CompanyPermissionGate;
use App\Authorization\CompanyPermissionMatrix;
use App\Enums\CompanyPermission;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Policies\AuditLogPolicy;
use App\Policies\Catalog\AttributeDefinitionPolicy;
use App\Policies\Catalog\AttributeOptionPolicy;
use App\Policies\Catalog\CategoryPolicy;
use App\Policies\Catalog\ProductMediaPolicy;
use App\Policies\Catalog\ProductPolicy;
use App\Policies\Catalog\ProductVariantPolicy;
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
        $gate->policy(AuditLog::class, AuditLogPolicy::class);
        $gate->policy(Company::class, CompanyPolicy::class);
        $gate->policy(CompanyMembership::class, CompanyMemberPolicy::class);
        $gate->policy(CompanyInvitation::class, CompanyInvitationPolicy::class);
        $gate->policy(Product::class, ProductPolicy::class);
        $gate->policy(ProductVariant::class, ProductVariantPolicy::class);
        $gate->policy(Category::class, CategoryPolicy::class);
        $gate->policy(AttributeDefinition::class, AttributeDefinitionPolicy::class);
        $gate->policy(AttributeOption::class, AttributeOptionPolicy::class);
        $gate->policy(ProductMedia::class, ProductMediaPolicy::class);

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
        $gate->define(CompanyPermission::CatalogView, CompanyPermissionGate::class.'@catalogView');
        $gate->define(CompanyPermission::CatalogCreate, CompanyPermissionGate::class.'@catalogCreate');
        $gate->define(CompanyPermission::CatalogUpdate, CompanyPermissionGate::class.'@catalogUpdate');
        $gate->define(CompanyPermission::CatalogArchive, CompanyPermissionGate::class.'@catalogArchive');
        $gate->define(CompanyPermission::CatalogPublish, CompanyPermissionGate::class.'@catalogPublish');
        $gate->define(CompanyPermission::CatalogManageCategories, CompanyPermissionGate::class.'@catalogManageCategories');
        $gate->define(CompanyPermission::CatalogManageAttributes, CompanyPermissionGate::class.'@catalogManageAttributes');
        $gate->define(CompanyPermission::CatalogManageMedia, CompanyPermissionGate::class.'@catalogManageMedia');
    }
}
