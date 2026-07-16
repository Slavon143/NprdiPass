<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    ProductPassportVersion::query()->delete();
    ProductPassport::query()->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

function createTestProduct(Company $company): Product
{
    return Product::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'name' => 'Test Product '.str()->random(5),
        'slug' => 'test-product-'.str()->random(5),
        'slug_normalized' => 'test-product-'.str()->random(5),
        'status' => ProductStatus::Active->value,
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createTestPassport(Company $company, ?Product $product = null): ProductPassport
{
    $product ??= createTestProduct($company);

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

test('one Passport per Company+Product — duplicate rejected', function () {
    $company = Company::factory()->create();
    $product = createTestProduct($company);

    createTestPassport($company, $product);

    $this->expectException(QueryException::class);

    createTestPassport($company, $product);
});

test('same Product in different Companies allowed', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $productA = createTestProduct($companyA);
    $productB = createTestProduct($companyB);

    $passportA = createTestPassport($companyA, $productA);
    $passportB = createTestPassport($companyB, $productB);

    expect($passportA->id)->not->toBe($passportB->id);
});

test('duplicate public_id rejected globally', function () {
    $company = Company::factory()->create();
    $product = createTestProduct($company);

    $publicId = Uuid::uuid7()->toString();

    DB::table('product_passports')->insert([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => $publicId,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => 'draft',
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_passports')->insert([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => $publicId,
        'company_id' => $company->id,
        'product_id' => createTestProduct($company)->id,
        'status' => 'draft',
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('invalid passport status rejected', function () {
    $company = Company::factory()->create();
    $product = createTestProduct($company);

    $this->expectException(QueryException::class);

    DB::table('product_passports')->insert([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => 'invalid_status',
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('invalid version status rejected', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->insert([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => 'invalid_status',
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('version number must be positive', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 0,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => hash('sha256', '{}'),
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('draft revision must be >= 1', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 0,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('checksum format enforced — rejects non-hex', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => 'NOT A VALID CHECKSUMZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ',
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('checksum format enforced — rejects invalid format', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->insert([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => 'NOT A VALID CHECKSUM! NOT HEX AT ALL! NOT HEX AT ALL! NOT HEX AT ALL!',
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('version number unique within Passport — duplicate rejected', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    ProductPassportVersion::query()->forceCreate([
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

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Superseded->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => hash('sha256', '{}'),
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'superseded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('same version number across different Passports allowed', function () {
    $company = Company::factory()->create();

    $passportA = createTestPassport($company);
    $passportB = createTestPassport($company);

    $v1 = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passportA->id,
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

    $v2 = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passportB->id,
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

    expect($v1->version_number)->toBe(1)
        ->and($v2->version_number)->toBe(1);
});

test('one active draft — second draft rejected', function () {
    $company = Company::factory()->create();
    $passport = createTestPassport($company);

    ProductPassportVersion::query()->forceCreate([
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

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->forceCreate([
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
});

test('draft for different Passport allowed simultaneously', function () {
    $company = Company::factory()->create();

    $passportA = createTestPassport($company);
    $passportB = createTestPassport($company);

    $draftA = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passportA->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $draftB = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'passport_id' => $passportB->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($draftA->id)->not->toBe($draftB->id);
});

test('version tenant isolation — cross-Company version rejected', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $passportA = createTestPassport($companyA);

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $companyB->id,
        'passport_id' => $passportA->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('public_id format — invalid UUID rejected', function () {
    $company = Company::factory()->create();
    $product = createTestProduct($company);

    $this->expectException(QueryException::class);

    ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => 'not-a-valid-uuid-v7-format',
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft->value,
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});
