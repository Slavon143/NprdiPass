<?php

use App\Models\Catalog\Product;
use App\Models\Company;

test('seeder sets multilingual configuration', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();

    // Product A: en default, en+sv enabled
    $productA = Product::query()->forCompany($company)
        ->where('slug_normalized', 'industrial-led-work-lamp')->first();
    $passportA = $productA->passport;
    expect($passportA->default_language)->toBe('en');
    expect($passportA->enabled_languages)->toContain('en');
    expect($passportA->enabled_languages)->toContain('sv');

    // Product B: en default, en+sv enabled
    $productB = Product::query()->forCompany($company)
        ->where('slug_normalized', 'fire-extinguisher-6kg')->first();
    $passportB = $productB->passport;
    expect($passportB->default_language)->toBe('en');
    expect($passportB->enabled_languages)->toContain('en');
    expect($passportB->enabled_languages)->toContain('sv');
});

test('product A public page supports language parameter', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'industrial-led-work-lamp')->first();
    $passport = $product->passport;

    // Default page (en)
    $response = $this->get("/p/{$passport->public_id}");
    $response->assertOk();

    // Swedish page
    $responseSv = $this->get("/p/{$passport->public_id}?lang=sv");
    $responseSv->assertOk();
});

test('product B public page works', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'fire-extinguisher-6kg')->first();
    $passport = $product->passport;

    $response = $this->get("/p/{$passport->public_id}");
    $response->assertOk();
});

test('product C public page returns 404', function (): void {
    $this->artisan('nordipass:demo:seed');

    $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
    $product = Product::query()->forCompany($company)
        ->where('slug_normalized', 'reflective-safety-vest')->first();
    $passport = $product->passport;

    $response = $this->get("/p/{$passport->public_id}");
    $response->assertNotFound();
});
