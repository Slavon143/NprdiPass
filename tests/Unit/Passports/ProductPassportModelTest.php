<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Ramsey\Uuid\Uuid;

function createProductForModelTest(Company $company): Product
{
    return Product::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'name' => 'Test Product '.str()->random(5),
        'slug' => 'test-product-'.str()->random(5),
        'slug_normalized' => str()->random(10),
        'status' => ProductStatus::Active->value,
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createTestPassportForModels(Company $company): ProductPassport
{
    $product = createProductForModelTest($company);

    return ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('ProductPassport casts status to enum', function () {
    $company = Company::factory()->create();
    $product = createProductForModelTest($company);

    $passport = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($passport->status)->toBeInstanceOf(ProductPassportStatus::class)
        ->and($passport->status)->toBe(ProductPassportStatus::Draft);
});

test('ProductPassport casts enabled_languages to array', function () {
    $company = Company::factory()->create();
    $passport = createTestPassportForModels($company);

    // Cast is applied when reading back through Eloquent
    $passport->enabled_languages = ['sv', 'en'];
    $passport->save();
    $passport->refresh();

    expect($passport->enabled_languages)->toBeArray()
        ->and($passport->enabled_languages)->toBe(['sv', 'en']);
});

test('ProductPassport relationships work', function () {
    $company = Company::factory()->create();
    $product = createProductForModelTest($company);

    $passport = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($passport->company->id)->toBe($company->id)
        ->and($passport->product->id)->toBe($product->id);
});

test('ProductPassport forCompany scope works', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $productA = createProductForModelTest($companyA);
    $productB = createProductForModelTest($companyB);

    $passportA = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scoped = ProductPassport::query()->forCompany($companyA)->get();
    expect($scoped)->toHaveCount(1)
        ->and($scoped->first()->id)->toBe($passportA->id);
});

test('ProductPassport helper predicates work', function () {
    $company = Company::factory()->create();
    $product = createProductForModelTest($company);

    $passport = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($passport->isDraft())->toBeTrue()
        ->and($passport->isPublished())->toBeFalse()
        ->and($passport->isUnpublished())->toBeFalse()
        ->and($passport->isArchived())->toBeFalse()
        ->and($passport->hasPublishedVersion())->toBeFalse();
});

test('ProductPassportVersion helper predicates work', function () {
    $company = Company::factory()->create();
    $passport = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => createProductForModelTest($company)->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $draft = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->isPublished())->toBeFalse()
        ->and($draft->isImmutable())->toBeFalse();
});

test('ProductPassport model guard prevents uuid mutation', function () {
    $company = Company::factory()->create();
    $product = createProductForModelTest($company);

    $passport = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('immutable');

    $passport->uuid = Uuid::uuid7()->toString();
    $passport->save();
});

test('ProductPassportVersion model guard prevents updating published', function () {
    $company = Company::factory()->create();
    $passport = ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => createProductForModelTest($company)->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => hash('sha256', '{}'),
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Published versions may only transition to superseded');

    $version->payload = json_encode(['changed' => true]);
    $version->save();
});

test('Company has productPassports relationship', function () {
    $company = Company::factory()->create();
    $product = createProductForModelTest($company);

    ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($company->productPassports)->toHaveCount(1);
});

test('Product has passport relationship', function () {
    $company = Company::factory()->create();
    $product = createProductForModelTest($company);

    ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product->refresh();
    expect($product->passport)->not->toBeNull();
});
