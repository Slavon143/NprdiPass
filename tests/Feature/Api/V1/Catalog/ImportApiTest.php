<?php

use App\Enums\ApiTokenAbility;
use App\Models\Imports\ImportJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Helpers.php';

test('import api enforces dedicated abilities and completes preview apply workflow', function () {
    Storage::fake('production_imports');
    [$user, $company] = apiCatalogContext();
    $token = apiToken($user, $company, [ApiTokenAbility::ImportsWrite->value, ApiTokenAbility::ImportsRead->value]);
    $upload = test()->withToken($token)->post(apiUrl('imports'), [
        'file' => UploadedFile::fake()->createWithContent('products.csv', "name,slug\nAPI Product,api-product"),
        'mode' => 'upsert', 'chunk_size' => 10,
    ]);
    $upload->assertCreated()->assertJsonPath('data.status', 'uploaded');
    $uuid = $upload->json('data.uuid');

    test()->withToken($token)->getJson(apiUrl("imports/{$uuid}"))
        ->assertOk()->assertJsonPath('data.source_filename', 'products.csv');
    test()->withToken($token)->postJson(apiUrl("imports/{$uuid}/preview"), ['mapping' => ['name' => 'name', 'slug' => 'slug']])
        ->assertOk()->assertJsonPath('data.valid_rows', 1)->assertJsonPath('data.records.0.operation', 'create');
    test()->withToken($token)->get(apiUrl("imports/{$uuid}/errors.csv"))
        ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    test()->withToken($token)->postJson(apiUrl("imports/{$uuid}/apply"))
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.created_rows', 1);

    app('auth')->forgetGuards();
    [$limitedUser, $limitedCompany] = apiCatalogContext();
    test()->withToken(apiToken($limitedUser, $limitedCompany, [ApiTokenAbility::CatalogWrite->value]))
        ->post(apiUrl('imports'), ['file' => UploadedFile::fake()->createWithContent('products.csv', "name\nDenied"), 'mode' => 'create'])
        ->assertForbidden()->assertJsonPath('error.code', 'token_ability_missing');
});

test('import API resources are hidden across tenants', function () {
    Storage::fake('production_imports');
    [$user, $company] = apiCatalogContext();
    $file = UploadedFile::fake()->createWithContent('products.csv', "name\nPrivate Product");
    $upload = test()->withToken(apiToken($user, $company, [ApiTokenAbility::ImportsWrite->value]))
        ->post(apiUrl('imports'), ['file' => $file, 'mode' => 'create'])->assertCreated();
    app('auth')->forgetGuards();
    [$otherUser, $otherCompany] = apiCatalogContext();

    apiGet($otherUser, $otherCompany, [ApiTokenAbility::ImportsRead->value], 'imports/'.$upload->json('data.uuid'))
        ->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
});

test('mapping templates are tenant scoped through API abilities', function () {
    [$user, $company] = apiCatalogContext();
    $token = apiToken($user, $company, [ApiTokenAbility::ImportsWrite->value, ApiTokenAbility::ImportsRead->value]);
    test()->withToken($token)->postJson(apiUrl('import-templates'), [
        'name' => 'Supplier A', 'mapping' => ['name' => 'Product name', 'slug' => 'Handle'],
    ])->assertCreated()->assertJsonPath('data.name', 'Supplier A');
    test()->withToken($token)->getJson(apiUrl('import-templates'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mapping.name', 'Product name');

    app('auth')->forgetGuards();
    [$otherUser, $otherCompany] = apiCatalogContext();
    apiGet($otherUser, $otherCompany, [ApiTokenAbility::ImportsRead->value], 'import-templates')
        ->assertOk()->assertJsonCount(0, 'data');
    expect(ImportJob::query()->forCompany($otherCompany)->count())->toBe(0)
        ->and($company->is($otherCompany))->toBeFalse();
});

test('product exports require exports read instead of catalog read', function () {
    [$user, $company] = apiCatalogContext();
    apiGet($user, $company, [ApiTokenAbility::ExportsRead->value], 'exports/products.csv')
        ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    app('auth')->forgetGuards();
    [$limitedUser, $limitedCompany] = apiCatalogContext();
    apiGet($limitedUser, $limitedCompany, [ApiTokenAbility::CatalogRead->value], 'exports/products.csv')
        ->assertForbidden()->assertJsonPath('error.code', 'token_ability_missing');
});

test('dangerous and ambiguous uploads are rejected before storage', function () {
    Storage::fake('production_imports');
    [$user, $company] = apiCatalogContext();
    $token = apiToken($user, $company, [ApiTokenAbility::ImportsWrite->value]);
    test()->withToken($token)->post(apiUrl('imports'), [
        'file' => UploadedFile::fake()->createWithContent('macros.xlsm', 'not-an-xlsx'), 'mode' => 'create',
    ])->assertUnprocessable();
    test()->withToken($token)->post(apiUrl('imports'), [
        'file' => UploadedFile::fake()->createWithContent('duplicate.csv', "name,name\nOne,Two"), 'mode' => 'create',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
    expect(Storage::disk('production_imports')->allFiles())->toBe([]);
});
