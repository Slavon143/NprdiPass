<?php

use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Models\User;
use App\Services\Catalog\Media\MediaStorageFence;
use App\Services\Catalog\Operations\CatalogMediaCleanupService;
use App\Services\Storage\TenantStorageLedger;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBeIn(['nordipass_testing', 'nordipass_commercial_20261004_testing', 'nordipass_reconcile_20261004_testing']);
    $this->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('catalog_media');
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Cleanup fencing');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    $this->product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Committed media']);
});

function cleanupFenceProbe(): string
{
    $path = app(MediaStorageFence::class)->lockPath('catalog_media');
    $process = new Process([PHP_BINARY, '-r', '$f=fopen($argv[1],"c+b"); if(!$f){exit(2);} echo flock($f,LOCK_EX|LOCK_NB) ? "available" : "busy"; fclose($f);', $path]);
    $process->setTimeout(10);
    $process->mustRun();

    return $process->getOutput();
}

test('media writer fence is shared across real processes and retained until ancestor decision', function (bool $variant, bool $commit) {
    DB::beginTransaction();
    DB::beginTransaction();
    try {
        $media = $variant
            ? app(UploadVariantMediaAction::class)->execute($this->actor, $this->company, $this->product, $this->product->defaultVariant, UploadedFile::fake()->image('lease.png'))
            : app(UploadProductMediaAction::class)->execute($this->actor, $this->company, $this->product, UploadedFile::fake()->image('lease.png'));
        $path = $media->storage_path;
        expect(cleanupFenceProbe())->toBe('busy');
        DB::commit();
        expect(cleanupFenceProbe())->toBe('busy');
        if ($commit) {
            DB::commit();
        } else {
            DB::rollBack();
        }
        expect(cleanupFenceProbe())->toBe('available')
            ->and(Storage::disk('catalog_media')->exists($path))->toBe($commit);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
})->with([[false, false], [true, false], [false, true], [true, true]]);

test('exclusive cleanup prevents a new local writer and releases access on failure', function () {
    $fence = app(MediaStorageFence::class);
    $lease = $fence->cleanup('catalog_media');
    try {
        expect(fn () => $fence->writer('catalog_media'))->toThrow(RuntimeException::class);
    } finally {
        $lease->release();
    }
    $writer = $fence->writer('catalog_media');
    expect(cleanupFenceProbe())->toBe('busy');
    $writer->release();
    expect(cleanupFenceProbe())->toBe('available');
});

test('cleanup retries committed soft deleted media but retains active references and unowned prefixes', function () {
    $media = app(UploadProductMediaAction::class)->execute($this->actor, $this->company, $this->product, UploadedFile::fake()->image('retry.png'));
    $other = app(UploadProductMediaAction::class)->execute($this->actor, $this->company, $this->product->fresh(), UploadedFile::fake()->image('active.png'));
    $this->product->forceFill(['primary_media_id' => $other->id])->save();
    $media->delete();
    Storage::disk('catalog_media')->put('unowned-prefix/orphan.png', 'retained');
    $this->artisan('catalog:prune-orphan-media', ['--delete' => true, '--older-than' => 0])->assertSuccessful();
    Storage::disk('catalog_media')->assertMissing($media->storage_path);
    Storage::disk('catalog_media')->assertExists($other->storage_path);
    Storage::disk('catalog_media')->assertExists('unowned-prefix/orphan.png');
});

test('cleanup releases confirmed seeded bytes and retains a durable unresolved operation', function () {
    $disk = Storage::disk('catalog_media');
    $seeded = $this->company->uuid.'/seeded-orphan.png';
    $pending = $this->company->uuid.'/pending.png';
    $disk->put($seeded, str_repeat('s', 15));
    $ledger = app(TenantStorageLedger::class);
    $reservation = $ledger->reserve($this->company, 'catalog_media', $pending, 20, (string) Str::uuid());
    $disk->put($pending, str_repeat('p', 20));
    expect($ledger->usedBytes($this->company))->toBe(35);
    $report = app(CatalogMediaCleanupService::class)->cleanup($this->company, false, 0);
    expect($report->deleted)->toBe(1)->and($report->skipped)->toBe(1)
        ->and($ledger->usedBytes($this->company))->toBe(20);
    $disk->assertMissing($seeded);
    $disk->assertExists($pending);
    $disk->delete($pending);
    $ledger->releaseConfirmed($this->company, $reservation);
});

test('remote cleanup is refused without a provider fencing implementation', function () {
    config(['filesystems.disks.catalog_media.driver' => 's3']);
    expect(fn () => app(MediaStorageFence::class)->cleanup('catalog_media'))->toThrow(RuntimeException::class);
});
