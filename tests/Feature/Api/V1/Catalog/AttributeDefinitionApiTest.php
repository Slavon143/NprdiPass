<?php

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyRole;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedApiAttributeDefinition(Company $company, User $actor, array $fields = []): AttributeDefinition
{
    $data = array_merge([
        'company_id' => $company->getKey(),
        'name' => $fields['name'] ?? 'Test Attribute',
        'code' => $fields['code'] ?? 'test_attribute',
        'type' => $fields['type'] ?? 'text',
        'scope' => $fields['scope'] ?? 'product',
        'required' => $fields['required'] ?? false,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ], $fields);

    $def = new AttributeDefinition;
    $def->forceFill($data)->save();

    return $def->refresh();
}

test('can list attribute definitions', function () {
    [$user, $company] = apiCatalogContext();
    seedApiAttributeDefinition($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'attributes');
    expect($res->status())->toBe(200);
    expect(count($res->json('data')))->toBeGreaterThanOrEqual(1);
});

test('can create attribute definition', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'attributes', [
        'name' => 'Color',
        'code' => 'color',
        'type' => 'select',
        'scope' => 'variant',
    ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.name'))->toBe('Color');
    expect($res->json('data.code'))->toBe('color');
});

test('can show attribute definition', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiAttributeDefinition($company, $user, ['name' => 'Size', 'code' => 'size', 'type' => 'select', 'scope' => 'variant']);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "attributes/{$def->uuid}");
    expect($res->status())->toBe(200);
    expect($res->json('data.uuid'))->toBe($def->uuid);
});

test('can update attribute definition', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiAttributeDefinition($company, $user);

    $res = apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "attributes/{$def->uuid}", [
        'name' => 'Updated Attribute',
    ]);
    expect($res->status())->toBe(200);
    expect($res->json('data.name'))->toBe('Updated Attribute');
});

test('can archive attribute definition', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiAttributeDefinition($company, $user);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("attributes/{$def->uuid}/archive"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe('archived');
});

test('can restore attribute definition', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiAttributeDefinition($company, $user, ['status' => 'archived']);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("attributes/{$def->uuid}/restore"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe('active');
});

test('duplicate attribute code returns conflict', function () {
    [$user, $company] = apiCatalogContext();
    seedApiAttributeDefinition($company, $user, ['name' => 'First', 'code' => 'dup_code']);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'attributes', [
        'name' => 'Second',
        'code' => 'dup_code',
        'type' => 'text',
        'scope' => 'product',
    ]);
    expect($res->status())->toBeIn([409, 422]);
});

test('wrong tenant attribute returns 404', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    $defB = seedApiAttributeDefinition($companyB, $userB);

    apiGet($userA, $companyA, [ApiTokenAbility::CatalogRead->value], "attributes/{$defB->uuid}")
        ->assertNotFound();
});

test('viewer cannot create attribute definition', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Viewer);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'attributes', [
        'name' => 'Test', 'code' => 'test', 'type' => 'text', 'scope' => 'product',
    ])->assertStatus(403);
});

test('editor cannot archive attribute definition', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Editor);
    $def = seedApiAttributeDefinition($company, $user);

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogLifecycle->value]))
        ->postJson(apiUrl("attributes/{$def->uuid}/archive"))
        ->assertStatus(403);
});
