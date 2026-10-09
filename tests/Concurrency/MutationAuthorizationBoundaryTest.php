<?php

use App\Actions\Api\CreateCompanyApiToken;
use App\Actions\Api\RevokeCompanyApiToken;
use App\Actions\Catalog\Categories\BulkArchiveCategoriesAction;
use App\Actions\Catalog\CreateProductWithDefaultVariantAction;
use App\Actions\Catalog\Documents\SubmitProductDocumentVersionForReviewAction;
use App\Actions\Companies\ResendCompanyInvitation;
use App\Actions\Companies\UpdateCompany;
use App\Actions\Fortnox\DisconnectFortnoxAction;
use App\Domain\Invitations\Exceptions\InvitationCannotBeResent;
use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Models\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('mutations reject a role revoked after preflight but before the transaction body', function (string $operation) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $category = Category::query()->forceCreate([
        'company_id' => $company->id, 'name' => 'Boundary', 'slug' => 'boundary',
        'slug_normalized' => 'boundary', 'status' => CategoryStatus::Active,
    ]);
    $product = Product::query()->forceCreate([
        'company_id' => $company->id, 'name' => 'Boundary', 'slug' => 'boundary',
        'slug_normalized' => 'boundary', 'status' => ProductStatus::Draft, 'created_by' => $actor->id,
    ]);
    $document = ProductDocument::factory()->create(['company_id' => $company->id, 'product_id' => $product->id]);
    $version = ProductDocumentVersion::factory()->forDocument($document)->create(['review_status' => ProductDocumentReviewStatus::Draft]);
    $token = issueCompanyApiToken($actor, $company, ['catalog.read'])->accessToken;
    $connection = FortnoxConnection::query()->forceCreate(['company_id' => $company->id, 'status' => FortnoxConnectionStatus::Active]);
    $auditCount = AuditLog::query()->where('company_id', $company->id)->count();
    $productCount = Product::query()->forCompany($company)->count();
    $tokenCount = PersonalAccessToken::query()->where('company_id', $company->id)->count();
    config()->set('database.connections.mysql_boundary_revoker', config('database.connections.mysql'));
    DB::purge('mysql_boundary_revoker');
    $primary = DB::connection();
    $revoked = false;
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($primary, $membership, &$revoked): void {
        if ($revoked || $event->connection !== $primary || $primary->transactionLevel() !== 1) {
            return;
        }
        $revoked = true;
        DB::connection('mysql_boundary_revoker')->table('company_user')->where('id', $membership->id)
            ->update(['role' => 'viewer', 'is_owner' => false]);
    });
    $mutation = fn () => match ($operation) {
        'legacy product' => app(CreateProductWithDefaultVariantAction::class)->execute($actor, $company, ['name' => 'Denied', 'slug' => 'denied'], []),
        'create token' => app(CreateCompanyApiToken::class)->execute($actor, $company, 'Denied', [ApiTokenAbility::CatalogRead], now()->addDay()),
        'revoke token' => app(RevokeCompanyApiToken::class)->execute($actor, $company, $token),
        'company' => app(UpdateCompany::class)->execute($actor, $company, ['name' => 'Denied']),
        'bulk category' => app(BulkArchiveCategoriesAction::class)->execute($actor, $company, [$category->uuid]),
        'document review' => app(SubmitProductDocumentVersionForReviewAction::class)->execute($actor, $company, $document, $version),
        'Fortnox disconnect' => app(DisconnectFortnoxAction::class)->execute($actor, $company),
    };

    try {
        expect($mutation)->toThrow(AuthorizationException::class);
        expect($revoked)->toBeTrue()
            ->and($company->fresh()->name)->toBe($company->name)
            ->and($category->fresh()->status)->toBe(CategoryStatus::Active)
            ->and($version->fresh()->review_status)->toBe(ProductDocumentReviewStatus::Draft)
            ->and($connection->fresh()->status)->toBe(FortnoxConnectionStatus::Active)
            ->and(PersonalAccessToken::query()->where('company_id', $company->id)->count())->toBe($tokenCount)
            ->and(Product::query()->forCompany($company)->count())->toBe($productCount)
            ->and(AuditLog::query()->where('company_id', $company->id)->count())->toBe($auditCount);
    } finally {
        DB::disconnect('mysql_boundary_revoker');
    }
})->with(['legacy product', 'create token', 'revoke token', 'company', 'bulk category', 'document review', 'Fortnox disconnect']);

test('resend rejects an invitation cancelled behind an older transaction snapshot', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    $invitation = CompanyInvitation::factory()->create(['company_id' => $company, 'role' => CompanyRole::Viewer]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    config()->set('database.connections.mysql_boundary_revoker', config('database.connections.mysql'));
    DB::purge('mysql_boundary_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        CompanyInvitation::query()->findOrFail($invitation->id);
        DB::connection('mysql_boundary_revoker')->table('company_invitations')->where('id', $invitation->id)->update(['cancelled_at' => now()]);
        expect(fn () => app(ResendCompanyInvitation::class)->execute($actor, $invitation))->toThrow(InvitationCannotBeResent::class);
        expect(CompanyInvitation::query()->where('company_id', $company->id)->count())->toBe(1)
            ->and(AuditLog::query()->where('company_id', $company->id)->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_boundary_revoker');
    }
});
