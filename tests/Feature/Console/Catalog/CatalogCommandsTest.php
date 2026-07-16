<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('catalog:integrity-check requires --company or --all-companies', function () {
    $this->artisan('catalog:integrity-check')
        ->assertExitCode(2);
});

test('catalog:integrity-check with --company scope works', function () {
    $company = Company::factory()->create();

    $this->artisan('catalog:integrity-check', [
        '--company' => $company->uuid,
    ])->assertExitCode(0);
});

test('catalog:integrity-check with --all-companies works', function () {
    Company::factory()->create();

    $this->artisan('catalog:integrity-check', [
        '--all-companies' => true,
    ])->assertExitCode(0);
});

test('catalog:integrity-check with invalid company UUID is rejected', function () {
    $this->artisan('catalog:integrity-check', [
        '--company' => 'not-a-uuid',
    ])->assertExitCode(2);
});

test('catalog:integrity-check with --format=json produces valid JSON', function () {
    $company = Company::factory()->create();

    Artisan::call('catalog:integrity-check', [
        '--company' => $company->uuid,
        '--format' => 'json',
    ]);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('summary')
        ->and($decoded)->toHaveKey('issues');
});

test('catalog:integrity-check with --format=table produces table', function () {
    $company = Company::factory()->create();

    $this->artisan('catalog:integrity-check', [
        '--company' => $company->uuid,
        '--format' => 'table',
    ])->assertExitCode(0);
});

test('catalog:integrity-check with --fail-on works', function () {
    $company = Company::factory()->create();

    DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd90',
        'company_id' => $company->id,
        'name' => '',
        'slug' => 'fail-on-test',
        'slug_normalized' => 'fail-on-test',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('catalog:integrity-check', [
        '--company' => $company->uuid,
        '--fail-on' => 'warning',
    ])->assertExitCode(1);
});

test('catalog:integrity-check is read-only', function () {
    $company = Company::factory()->create();

    DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc90',
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Readonly',
        'slug' => 'readonly',
        'slug_normalized' => 'readonly',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $before = DB::table('categories')->where('company_id', $company->id)->count();

    $this->artisan('catalog:integrity-check', [
        '--company' => $company->uuid,
    ])->assertExitCode(0);

    $after = DB::table('categories')->where('company_id', $company->id)->count();

    expect($after)->toBe($before);
});

test('catalog:summary requires --company or --all-companies', function () {
    $this->artisan('catalog:summary')
        ->assertExitCode(2);
});

test('catalog:summary with --company scope works', function () {
    $company = Company::factory()->create();

    $this->artisan('catalog:summary', [
        '--company' => $company->uuid,
    ])->assertExitCode(0);
});

test('catalog:summary with --format=json produces valid JSON', function () {
    $company = Company::factory()->create();

    Artisan::call('catalog:summary', [
        '--company' => $company->uuid,
        '--format' => 'json',
    ]);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray()
        ->and($decoded[0])->toHaveKey('company_uuid')
        ->and($decoded[0])->toHaveKey('company_name');
});

test('catalog:media-cleanup enforces dry-run by default', function () {
    Storage::fake('catalog_media');

    $company = Company::factory()->create();

    $this->artisan('catalog:media-cleanup', [
        '--company' => $company->uuid,
    ])->assertExitCode(0);
});

test('catalog:media-cleanup --execute deletes orphan files', function () {
    Storage::fake('catalog_media');
    config()->set('catalog.media.cleanup_older_than_hours', 0);
    config()->set('catalog.media.cleanup_limit', 500);
    $disk = Storage::disk('catalog_media');

    $company = Company::factory()->create();
    $orphanPath = "{$company->uuid}/orphan-file.jpg";
    $disk->put($orphanPath, 'fake image data');

    touch($disk->path($orphanPath), now()->subHours(2)->timestamp);

    $this->artisan('catalog:media-cleanup', [
        '--company' => $company->uuid,
        '--execute' => true,
        '--older-than' => 1,
    ])->assertExitCode(0);

    expect($disk->exists($orphanPath))->toBeFalse();
});

test('catalog:media-cleanup preserves referenced files', function () {
    Storage::fake('catalog_media');
    config()->set('catalog.media.cleanup_older_than_hours', 0);
    $disk = Storage::disk('catalog_media');

    $company = Company::factory()->create();
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd91',
        'company_id' => $company->id,
        'name' => 'Media Product',
        'slug' => 'media-product',
        'slug_normalized' => 'media-product',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mediaPath = "{$company->uuid}/referenced-file.jpg";
    $disk->put($mediaPath, 'fake image data');

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff4',
        'company_id' => $company->id,
        'product_id' => $productId,
        'original_filename' => 'referenced.jpg',
        'storage_path' => $mediaPath,
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    touch($disk->path($mediaPath), now()->subHours(2)->timestamp);

    $this->artisan('catalog:media-cleanup', [
        '--company' => $company->uuid,
        '--execute' => true,
        '--older-than' => 1,
    ])->assertExitCode(0);

    expect($disk->exists($mediaPath))->toBeTrue();
});

test('catalog:media-cleanup --dry-run and --execute cannot be combined', function () {
    $company = Company::factory()->create();

    $this->artisan('catalog:media-cleanup', [
        '--company' => $company->uuid,
        '--dry-run' => true,
        '--execute' => true,
    ])->assertExitCode(2);
});

test('catalog:media-cleanup respects --limit', function () {
    Storage::fake('catalog_media');
    config()->set('catalog.media.cleanup_older_than_hours', 0);
    config()->set('catalog.media.cleanup_limit', 500);
    $disk = Storage::disk('catalog_media');

    $company = Company::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $path = "{$company->uuid}/orphan-{$i}.jpg";
        $disk->put($path, 'fake image data');
        touch($disk->path($path), now()->subHours(2)->timestamp);
    }

    $this->artisan('catalog:media-cleanup', [
        '--company' => $company->uuid,
        '--execute' => true,
        '--older-than' => 1,
        '--limit' => 2,
    ])->assertExitCode(0);

    $remaining = collect($disk->allFiles())->filter(
        fn ($path) => str_starts_with($path, (string) $company->uuid.'/'),
    );

    expect($remaining)->toHaveCount(3);
});

test('catalog:media-cleanup respects --older-than', function () {
    Storage::fake('catalog_media');
    config()->set('catalog.media.cleanup_older_than_hours', 0);
    $disk = Storage::disk('catalog_media');

    $company = Company::factory()->create();

    $recentPath = "{$company->uuid}/recent-file.jpg";
    $disk->put($recentPath, 'fake image data');

    touch($disk->path($recentPath), now()->subMinutes(30)->timestamp);

    $this->artisan('catalog:media-cleanup', [
        '--company' => $company->uuid,
        '--execute' => true,
        '--older-than' => 1,
    ])->assertExitCode(0);

    expect($disk->exists($recentPath))->toBeTrue();
});

test('catalog:media-cleanup returns valid JSON output', function () {
    Storage::fake('catalog_media');
    $company = Company::factory()->create();

    Artisan::call('catalog:media-cleanup', [
        '--company' => $company->uuid,
        '--format' => 'json',
    ]);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray()
        ->and($decoded[0])->toHaveKey('scanned')
        ->and($decoded[0])->toHaveKey('deleted');
});
