<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    ProductPassportAsset::query()->delete();
    ProductPassportVersion::query()->delete();
    ProductPassport::query()->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

function createProductForImmutabilityTest(Company $company): Product
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

function makeTestPassport(): ProductPassport
{
    $company = Company::factory()->create();
    $product = createProductForImmutabilityTest($company);

    return ProductPassport::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $company->id,
        'product_id' => $product->id,
        'status' => ProductPassportStatus::Draft,
        'default_language' => 'sv',
        'enabled_languages' => ['sv', 'en'],
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('passport identity immutable — uuid cannot be updated via query builder', function () {
    $passport = makeTestPassport();
    $originalUuid = $passport->uuid;

    $this->expectException(QueryException::class);

    ProductPassport::query()->where('id', $passport->id)->update(['uuid' => Uuid::uuid7()->toString()]);
});

test('passport identity immutable — public_id cannot be updated via query builder', function () {
    $passport = makeTestPassport();
    $originalPublicId = $passport->public_id;

    $this->expectException(QueryException::class);

    ProductPassport::query()->where('id', $passport->id)->update(['public_id' => Uuid::uuid7()->toString()]);
});

test('passport identity immutable — company_id cannot be updated', function () {
    $passport = makeTestPassport();
    $newCompany = Company::factory()->create();

    $this->expectException(QueryException::class);

    ProductPassport::query()->where('id', $passport->id)->update(['company_id' => $newCompany->id]);
});

test('passport identity immutable — product_id cannot be updated', function () {
    $passport = makeTestPassport();
    $newProduct = createProductForImmutabilityTest($passport->company);

    $this->expectException(QueryException::class);

    ProductPassport::query()->where('id', $passport->id)->update(['product_id' => $newProduct->id]);
});

test('passport identity immutable via raw SQL', function () {
    $passport = makeTestPassport();

    $this->expectException(QueryException::class);

    DB::statement('UPDATE product_passports SET uuid = ? WHERE id = ?', [Uuid::uuid7()->toString(), $passport->id]);
});

test('published version is immutable — cannot update payload', function () {
    $passport = makeTestPassport();

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['test' => 'data']),
        'content_checksum' => hash('sha256', json_encode(['test' => 'data'])),
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->where('id', $version->id)->update(['payload' => json_encode(['changed' => true])]);
});

test('published version is immutable — cannot delete', function () {
    $passport = makeTestPassport();

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
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

    ProductPassportVersion::query()->where('id', $version->id)->delete();
});

test('published version content immutable via raw SQL update', function () {
    $passport = makeTestPassport();

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['v' => 1]),
        'content_checksum' => hash('sha256', json_encode(['v' => 1])),
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement('UPDATE product_passport_versions SET payload = ? WHERE id = ?', [json_encode(['v' => 2]), $version->id]);
});

test('superseded version is immutable', function () {
    $passport = makeTestPassport();

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
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

    $this->expectException(QueryException::class);

    ProductPassportVersion::query()->where('id', $version->id)->update(['status' => 'draft']);
});

test('draft version is mutable — can update payload', function () {
    $passport = makeTestPassport();

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['original' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updated = ProductPassportVersion::query()->where('id', $version->id)->update([
        'payload' => json_encode(['updated' => true]),
        'draft_revision' => 2,
    ]);

    expect($updated)->toBe(1);

    $version->refresh();
    expect($version->payload)->toBe(['updated' => true])
        ->and($version->draft_revision)->toBe(2);
});

test('asset asset update blocked', function () {
    $passport = makeTestPassport();

    $version = ProductPassportVersion::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $asset = ProductPassportAsset::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'version_id' => $version->id,
        'kind' => 'product_media',
        'source_resource_uuid' => Uuid::uuid7()->toString(),
        'sort_order' => 10,
        'mime_type' => 'image/jpeg',
        'file_extension' => 'jpg',
        'size_bytes' => 102400,
        'width' => 1200,
        'height' => 800,
        'checksum_sha256' => hash('sha256', 'test'),
        'storage_key' => 'companies/xxx/passports/yyy/versions/1/zzz.jpg',
        'is_public' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    ProductPassportAsset::query()->where('id', $asset->id)->update(['size_bytes' => 999]);
});

test('passport status can be changed via Eloquent (lifecycle transition)', function () {
    $passport = makeTestPassport();
    expect($passport->status)->toBe(ProductPassportStatus::Draft);

    $passport->status = ProductPassportStatus::Published;
    $passport->first_published_at = now();
    $passport->last_published_at = now();
    $passport->save();

    $passport->refresh();
    expect($passport->status)->toBe(ProductPassportStatus::Published);
});

test('draft version payload update accepted via query builder', function () {
    $passport = makeTestPassport();
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['original' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updated = DB::table('product_passport_versions')->where('id', $versionId)->update([
        'payload' => json_encode(['updated' => true]),
        'draft_revision' => 2,
    ]);

    expect($updated)->toBe(1);
});

test('draft → published transition accepted via query builder', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode(['content' => 'v1']));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Draft->value,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['content' => 'v1']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updated = DB::table('product_passport_versions')->where('id', $versionId)->update([
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
    ]);

    expect($updated)->toBe(1);
});

test('published payload update rejected via query builder', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode(['content' => 'v1']));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['content' => 'v1']),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->where('id', $versionId)->update([
        'payload' => json_encode(['content' => 'changed']),
    ]);
});

test('published → draft rejected via query builder', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->where('id', $versionId)->update(['status' => 'draft']);
});

test('published → superseded accepted via query builder', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updated = DB::table('product_passport_versions')->where('id', $versionId)->update([
        'status' => ProductPassportVersionStatus::Superseded->value,
        'superseded_at' => now(),
    ]);

    expect($updated)->toBe(1);
});

test('published → withdrawn accepted via query builder', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updated = DB::table('product_passport_versions')->where('id', $versionId)->update([
        'status' => ProductPassportVersionStatus::Withdrawn->value,
        'withdrawn_at' => now(),
    ]);

    expect($updated)->toBe(1);
});

test('published → superseded with payload change rejected', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode(['v' => 1]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode(['v' => 1]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->where('id', $versionId)->update([
        'status' => ProductPassportVersionStatus::Superseded->value,
        'superseded_at' => now(),
        'payload' => json_encode(['v' => 2]),
    ]);
});

test('published → withdrawn with checksum change rejected', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Published->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->where('id', $versionId)->update([
        'status' => ProductPassportVersionStatus::Withdrawn->value,
        'withdrawn_at' => now(),
        'content_checksum' => hash('sha256', 'changed'),
    ]);
});

test('superseded update rejected via raw SQL', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Superseded->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'superseded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement('UPDATE product_passport_versions SET status = ? WHERE id = ?', [ProductPassportVersionStatus::Draft->value, $versionId]);
});

test('superseded delete rejected via raw SQL', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Superseded->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'superseded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement('DELETE FROM product_passport_versions WHERE id = ?', [$versionId]);
});

test('withdrawn update rejected', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Withdrawn->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'withdrawn_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_passport_versions')->where('id', $versionId)->update(['status' => 'draft']);
});

test('withdrawn delete rejected', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Withdrawn->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'withdrawn_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement('DELETE FROM product_passport_versions WHERE id = ?', [$versionId]);
});

test('superseded → published rejected', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Superseded->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'superseded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement("UPDATE product_passport_versions SET status = 'published' WHERE id = ?", [$versionId]);
});

test('withdrawn → published rejected', function () {
    $passport = makeTestPassport();
    $checksum = hash('sha256', json_encode([]));
    $versionId = DB::table('product_passport_versions')->insertGetId([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $passport->company_id,
        'passport_id' => $passport->id,
        'status' => ProductPassportVersionStatus::Withdrawn->value,
        'version_number' => 1,
        'draft_revision' => 1,
        'schema_version' => '1.0',
        'payload' => json_encode([]),
        'content_checksum' => $checksum,
        'published_at' => now(),
        'published_by' => User::factory()->create()->id,
        'withdrawn_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement("UPDATE product_passport_versions SET status = 'published' WHERE id = ?", [$versionId]);
});
