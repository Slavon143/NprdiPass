<?php

use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    ProductPassportAsset::query()->delete();
    ProductPassportVersion::query()->delete();
    ProductPassport::query()->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

test('ProductPassportFactory default state is valid', function () {
    $passport = ProductPassport::factory()->create();

    expect($passport->exists)->toBeTrue()
        ->and($passport->uuid)->not->toBeEmpty()
        ->and($passport->status)->toBe(ProductPassportStatus::Draft);
});

test('ProductPassportFactory published state is valid', function () {
    $passport = ProductPassport::factory()->published()->create();

    expect($passport->status)->toBe(ProductPassportStatus::Published)
        ->and($passport->first_published_at)->not->toBeNull();
});

test('ProductPassportFactory unpublished state is valid', function () {
    $passport = ProductPassport::factory()->unpublished()->create();

    expect($passport->status)->toBe(ProductPassportStatus::Unpublished);
});

test('ProductPassportFactory archived state is valid', function () {
    $passport = ProductPassport::factory()->archived()->create();

    expect($passport->status)->toBe(ProductPassportStatus::Archived)
        ->and($passport->archived_at)->not->toBeNull();
});

test('ProductPassportVersionFactory draft state is valid', function () {
    $passport = ProductPassport::factory()->create();
    $version = ProductPassportVersion::factory()
        ->for($passport, 'passport')
        ->draft()
        ->create(['company_id' => $passport->company_id]);

    expect($version->exists)->toBeTrue()
        ->and($version->status)->toBe(ProductPassportVersionStatus::Draft)
        ->and($version->version_number)->toBeNull()
        ->and($version->published_at)->toBeNull();
});

test('ProductPassportVersionFactory published state is valid', function () {
    $passport = ProductPassport::factory()->create();
    $version = ProductPassportVersion::factory()
        ->for($passport, 'passport')
        ->published()
        ->create(['company_id' => $passport->company_id]);

    expect($version->status)->toBe(ProductPassportVersionStatus::Published)
        ->and($version->version_number)->toBe(1)
        ->and($version->published_at)->not->toBeNull()
        ->and($version->content_checksum)->not->toBeNull();
});

test('ProductPassportAssetFactory productMedia state is valid', function () {
    $passport = ProductPassport::factory()->create();
    $version = ProductPassportVersion::factory()
        ->for($passport, 'passport')
        ->draft()
        ->create(['company_id' => $passport->company_id]);

    $asset = ProductPassportAsset::factory()
        ->for($passport, 'passport')
        ->for($version, 'version')
        ->productMedia()
        ->create(['company_id' => $passport->company_id]);

    expect($asset->exists)->toBeTrue()
        ->and($asset->checksum_sha256)->toMatch('/^[0-9a-f]{64}$/');
});
