<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedApiSelectDefinition(Company $company, User $actor, string $name = 'Color', string $code = 'color'): AttributeDefinition
{
    $def = new AttributeDefinition;
    $def->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'code' => $code,
        'type' => 'select',
        'scope' => 'variant',
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $def->refresh();
}

function seedApiOption(AttributeDefinition $definition, Company $company, array $fields = []): AttributeOption
{
    $data = array_merge([
        'company_id' => $company->getKey(),
        'attribute_definition_id' => $definition->getKey(),
        'label' => $fields['label'] ?? 'Option 1',
        'code' => $fields['code'] ?? 'option_1',
        'sort_order' => $fields['sort_order'] ?? 0,
        'status' => 'active',
    ], $fields);

    $opt = new AttributeOption;
    $opt->forceFill($data)->save();

    return $opt->refresh();
}

test('can list options', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);
    seedApiOption($def, $company, ['label' => 'Red', 'code' => 'red']);
    seedApiOption($def, $company, ['label' => 'Blue', 'code' => 'blue']);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "attributes/{$def->uuid}/options");
    expect($res->status())->toBe(200);
    expect(count($res->json('data')))->toBeGreaterThanOrEqual(2);
});

test('can create option', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "attributes/{$def->uuid}/options", [
        'label' => 'Green',
        'code' => 'green',
        'sort_order' => 10,
    ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.label'))->toBe('Green');
});

test('can update option', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);
    $opt = seedApiOption($def, $company, ['label' => 'Old', 'code' => 'old']);

    $res = apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "attributes/{$def->uuid}/options/{$opt->id}", [
        'label' => 'New Label',
    ]);
    expect($res->status())->toBe(200);
    expect($res->json('data.label'))->toBe('New Label');
});

test('can archive option', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);
    $opt = seedApiOption($def, $company);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("attributes/{$def->uuid}/options/{$opt->id}/archive"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe('archived');
});

test('can restore option', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);
    $opt = seedApiOption($def, $company, ['status' => 'archived']);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("attributes/{$def->uuid}/options/{$opt->id}/restore"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe('active');
});

test('can reorder options', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);
    $opt1 = seedApiOption($def, $company, ['label' => 'First', 'code' => 'first', 'sort_order' => 10]);
    $opt2 = seedApiOption($def, $company, ['label' => 'Second', 'code' => 'second', 'sort_order' => 20]);

    $res = apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "attributes/{$def->uuid}/options/reorder", [
        'ordered_uuids' => [$opt2->id, $opt1->id],
    ]);
    expect($res->status())->toBe(200);
});

test('wrong definition option returns 404', function () {
    [$user, $company] = apiCatalogContext();
    $def1 = seedApiSelectDefinition($company, $user, 'Def 1', 'def1');
    $def2 = seedApiSelectDefinition($company, $user, 'Def 2', 'def2');
    $opt = seedApiOption($def2, $company);

    apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "attributes/{$def1->uuid}/options/{$opt->id}", [
        'label' => 'Should 404',
    ])->assertNotFound();
});

test('duplicate option code returns conflict', function () {
    [$user, $company] = apiCatalogContext();
    $def = seedApiSelectDefinition($company, $user);
    seedApiOption($def, $company, ['label' => 'First', 'code' => 'dup']);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "attributes/{$def->uuid}/options", [
        'label' => 'Second', 'code' => 'dup', 'sort_order' => 10,
    ]);
    expect($res->status())->toBeIn([409, 422]);
});
