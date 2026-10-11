<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Readiness\ProductIndexReadinessProvider;

test('dashboard offers publication only for fresh unblocked readiness and an authorized member', function (string $source, string $status, int $blockers, string $role, bool $publish) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'role' => $role,
    ]);
    $product = Product::query()->forceCreate([
        'company_id' => $company->id,
        'name' => 'Publication boundary product',
        'slug' => 'publication-boundary-product',
        'slug_normalized' => 'publication-boundary-product',
        'status' => ProductStatus::Active,
        'created_by' => $user->id,
        'managed' => true,
    ]);
    $passport = ProductPassport::query()->forceCreate([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'en',
        'enabled_languages' => ['en'],
        'created_by' => $user->id,
    ]);
    $this->mock(ProductIndexReadinessProvider::class)->shouldReceive('loadSummaries')->once()->andReturn([
        $product->uuid => [
            'passport_uuid' => $passport->uuid,
            'passport_status' => 'draft',
            'readiness_source' => $source,
            'readiness_status' => $status,
            'score' => 97,
            'blockers' => $blockers,
            'warnings' => 1,
            'recommendations' => 0,
        ],
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $target = route('catalog.products.passport.publish-confirm', $product->uuid);
    if ($publish) {
        $response->assertSee($target, false)->assertSee('Publish passport');
    } else {
        $response->assertDontSee($target, false)->assertSee('Review readiness');
    }
})->with([
    'high score with a blocker' => ['live', 'not_ready', 1, 'owner', false],
    'stored evaluation' => ['stored', 'ready', 0, 'owner', false],
    'ready owner' => ['live', 'ready', 0, 'owner', true],
    'owner with warnings' => ['live', 'ready_with_warnings', 0, 'owner', true],
    'viewer without publication permission' => ['live', 'ready', 0, 'viewer', false],
]);
