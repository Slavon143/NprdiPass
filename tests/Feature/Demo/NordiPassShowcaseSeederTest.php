<?php

use App\Enums\CompanyRole;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Qr\PassportQrPayloadFactory;

test('command refuses production', function (): void {
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan('nordipass:demo:seed')
        ->assertFailed();
});

test('command runs in testing environment', function (): void {
    $this->artisan('nordipass:demo:seed')
        ->assertSuccessful();
});

test('command runs in local environment', function (): void {
    $this->app->detectEnvironment(fn () => 'local');

    $this->artisan('nordipass:demo:seed')
        ->assertSuccessful();
});

test('creates one demo company', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    expect($company)->not()->toBeNull();
});

test('creates expected demo users', function (): void {
    $this->artisan('nordipass:demo:seed');

    expect(User::query()->where('email', 'demo.owner@nordipass.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'demo.admin@nordipass.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'demo.editor@nordipass.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'demo.viewer@nordipass.test')->exists())->toBeTrue();
});

test('demo users have correct roles', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $owner = User::query()->where('email', 'demo.owner@nordipass.test')->first();
    $admin = User::query()->where('email', 'demo.admin@nordipass.test')->first();
    $editor = User::query()->where('email', 'demo.editor@nordipass.test')->first();
    $viewer = User::query()->where('email', 'demo.viewer@nordipass.test')->first();

    expect($owner->memberships()->where('company_id', $company->getKey())->first()->role)
        ->toBe(CompanyRole::Owner);
    expect($admin->memberships()->where('company_id', $company->getKey())->first()->role)
        ->toBe(CompanyRole::Admin);
    expect($editor->memberships()->where('company_id', $company->getKey())->first()->role)
        ->toBe(CompanyRole::Editor);
    expect($viewer->memberships()->where('company_id', $company->getKey())->first()->role)
        ->toBe(CompanyRole::Viewer);
});

test('creates exactly six demo products', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $count = Product::query()->forCompany($company)->count();

    expect($count)->toBe(6);
});

test('creates published Version 2 for LED lamp', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'industrial-led-work-lamp')->first();

    $passport = $product->passport;
    expect($passport)->not()->toBeNull();
    expect($passport->isPublished())->toBeTrue();

    $publishedVersion = $passport->currentPublishedVersion;
    expect($publishedVersion)->not()->toBeNull();
    expect($publishedVersion->version_number)->toBe(2);
});

test('creates published Version 1 for extinguisher', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'fire-extinguisher-6kg')->first();

    $passport = $product->passport;
    expect($passport)->not()->toBeNull();
    expect($passport->isPublished())->toBeTrue();

    $publishedVersion = $passport->currentPublishedVersion;
    expect($publishedVersion)->not()->toBeNull();
    expect($publishedVersion->version_number)->toBe(1);
});

test('creates not-ready draft for safety vest', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'reflective-safety-vest')->first();

    $passport = $product->passport;
    expect($passport)->not()->toBeNull();
    expect($passport->isDraft())->toBeTrue();
    expect($passport->hasPublishedVersion())->toBeFalse();
});

test('creates unpublished passport for work gloves', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'progrip-work-gloves')->first();

    $passport = $product->passport;
    expect($passport)->not()->toBeNull();
    expect($passport->isUnpublished())->toBeTrue();
    expect($passport->versions()->where('status', 'withdrawn')->count())->toBe(1);
});

test('creates archived passport for cordless drill', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'cordless-drill-18v')->first();

    $passport = $product->passport;
    expect($passport)->not()->toBeNull();
    expect($passport->isArchived())->toBeTrue();
});

test('creates product without passport for storage case', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'industrial-storage-case')->first();

    expect($product)->not()->toBeNull();
    expect($product->passport)->toBeNull();
});

test('second run creates no duplicate products', function (): void {
    $this->artisan('nordipass:demo:seed');
    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $countBefore = Product::query()->forCompany($company)->count();

    $this->artisan('nordipass:demo:seed');
    $countAfter = Product::query()->forCompany($company)->count();

    expect($countAfter)->toBe($countBefore);
});

test('second run creates no duplicate passports', function (): void {
    $this->artisan('nordipass:demo:seed');
    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $countBefore = ProductPassport::query()->forCompany($company)->count();

    $this->artisan('nordipass:demo:seed');
    $countAfter = ProductPassport::query()->forCompany($company)->count();

    expect($countAfter)->toBe($countBefore);
});

test('second run creates no duplicate users', function (): void {
    $this->artisan('nordipass:demo:seed');
    $countBefore = User::query()->where('email', 'like', 'demo.%@nordipass.test')->count();

    $this->artisan('nordipass:demo:seed');
    $countAfter = User::query()->where('email', 'like', 'demo.%@nordipass.test')->count();

    expect($countAfter)->toBe($countBefore);
});

test('reset removes only demo company data', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $this->artisan('nordipass:demo:seed', ['--reset' => true]);

    $productCount = Product::query()->forCompany($company)->count();
    expect($productCount)->toBe(0);
});

test('stable public urls work for published passports', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'industrial-led-work-lamp')->first();

    $passport = $product->passport;
    $publicId = $passport->public_id;

    $response = $this->get("/p/{$publicId}");
    $response->assertOk();
});

test('qr payload is stable and uses public id', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'industrial-led-work-lamp')->first();

    $passport = $product->passport;
    $payloadFactory = app(PassportQrPayloadFactory::class);
    $payload = $payloadFactory->create($passport->public_id);

    expect($payload)->toContain("/p/{$passport->public_id}");
    expect($payload)->not()->toContain('version');
});

test('unpublished passport public page returns 404', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'progrip-work-gloves')->first();

    $passport = $product->passport;
    $response = $this->get("/p/{$passport->public_id}");
    $response->assertNotFound();
});

test('archived passport public page returns 404', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'cordless-drill-18v')->first();

    $passport = $product->passport;
    $response = $this->get("/p/{$passport->public_id}");
    $response->assertNotFound();
});

test('draft passport public page returns 404', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'reflective-safety-vest')->first();

    $passport = $product->passport;
    $response = $this->get("/p/{$passport->public_id}");
    $response->assertNotFound();
});
