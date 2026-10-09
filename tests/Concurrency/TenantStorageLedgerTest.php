<?php

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Storage\TenantStorageInventory;
use App\Services\Storage\TenantStorageLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    if (! in_array(DB::connection()->getDatabaseName(), ['nordipass_testing', 'nordipass_reconcile_20261004_testing', 'nordipass_commercial_20261004_testing'], true)) {
        throw new RuntimeException('Owned isolated ledger schema required.');
    }
    $this->artisan('migrate')->assertSuccessful();
    DB::beginTransaction();
    $this->company = Company::factory()->create(['commercial_model_version' => 1]);
    $this->subscription = CompanySubscription::query()->create([
        'company_id' => $this->company->id, 'plan_key' => 'starter',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['storage.bytes.max' => 100],
    ]);
    foreach (['catalog_media', 'product_documents', 'production_imports', 'passport_assets'] as $disk) {
        Storage::fake($disk);
    }
    config(['catalog.media.disk' => 'catalog_media', 'documents.disk' => 'product_documents', 'imports.disk' => 'production_imports']);
    $this->ledger = app(TenantStorageLedger::class);
    $this->key = $this->company->uuid.'/products/owned.bin';
    $this->operation = (string) Str::uuid();
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (DB::getConnections() as $name => $connection) {
        if (str_starts_with($name, 'commercial_storage_')) {
            if ($connection->getDatabaseName() !== DB::connection()->getDatabaseName()) {
                throw new RuntimeException('Refusing ledger cleanup outside owned test schema.');
            }
            $connection->table('commercial_storage_objects')->where('company_uuid', $this->company->uuid)->delete();
            $connection->table('commercial_storage_balances')->where('company_uuid', $this->company->uuid)->delete();
        }
    }
    DB::table('company_subscriptions')->where('company_id', $this->company->id)->delete();
    DB::table('companies')->where('uuid', $this->company->uuid)->delete();
});

function storageCompetingConnection(): void
{
    $configuration = DB::connection()->getConfig();
    config(['database.connections.mysql_storage_competitor' => [...$configuration, 'name' => 'mysql_storage_competitor']]);
    DB::purge('mysql_storage_competitor');
    DB::connection('mysql_storage_competitor')->statement('SET SESSION innodb_lock_wait_timeout = 1');
}

test('durable reservation survives parent rollback with an uncommitted tenant', function () {
    $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    DB::rollBack();
    expect($this->ledger->usedBytes($this->company))->toBe(70)
        ->and(Company::query()->whereKey($this->company->id)->exists())->toBeFalse();
});

test('retry charges once and a second operation cannot claim the same physical path', function () {
    $first = $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    $again = $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    expect($first->physicalKey)->toBe($again->physicalKey)->and($this->ledger->usedBytes($this->company))->toBe(70);
    expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect($this->ledger->usedBytes($this->company))->toBe(70);
});

test('existing retained bytes reduce available quota and denial preserves the initialized balance', function () {
    Storage::disk('product_documents')->put('companies/'.$this->company->uuid.'/history.bin', str_repeat('x', 40));
    expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', $this->key, 61, $this->operation))
        ->toThrow(ValidationException::class, 'allowance');
    expect($this->ledger->usedBytes($this->company))->toBe(40);
    $this->ledger->reserve($this->company, 'catalog_media', $this->key, 60, $this->operation);
    expect($this->ledger->usedBytes($this->company))->toBe(100);
});

test('physical writes refund unused reservation only once and existing bytes prevent release', function () {
    $claim = $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    Storage::disk('catalog_media')->put($this->key, str_repeat('x', 50));
    $this->ledger->markWritten($this->company, $claim);
    $this->ledger->markWritten($this->company, $claim);
    expect($this->ledger->usedBytes($this->company))->toBe(50);
    expect(fn () => $this->ledger->releaseConfirmed($this->company, $claim))->toThrow(LogicException::class, 'still exist');
    Storage::disk('catalog_media')->delete($this->key);
    $this->ledger->releaseConfirmed($this->company, $claim);
    $this->ledger->releaseConfirmed($this->company, $claim);
    expect($this->ledger->usedBytes($this->company))->toBe(0);
});

test('foreign tenant paths are rejected before quota mutation', function () {
    expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', Str::uuid().'/foreign.bin', 10, $this->operation))
        ->toThrow(RuntimeException::class, 'outside');
    expect($this->ledger->usedBytes($this->company))->toBeNull();
});

test('an oversized failed write retains its actual charge until physical removal is confirmed', function () {
    $claim = $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    Storage::disk('catalog_media')->put($this->key, str_repeat('x', 80));
    expect(fn () => $this->ledger->markWritten($this->company, $claim))->toThrow(LogicException::class, 'exceed');
    expect($this->ledger->usedBytes($this->company))->toBe(80);
    expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', $this->company->uuid.'/too-large.bin', 21, (string) Str::uuid()))
        ->toThrow(ValidationException::class, 'allowance');
    Storage::disk('catalog_media')->delete($this->key);
    $this->ledger->releaseConfirmed($this->company, $claim);
    expect($this->ledger->usedBytes($this->company))->toBe(0);
});

test('confirmed removal releases seeded retained bytes without erasing operation history', function () {
    $path = 'companies/'.$this->company->uuid.'/retained.bin';
    Storage::disk('product_documents')->put($path, str_repeat('x', 40));
    $this->ledger->reserve($this->company, 'catalog_media', $this->key, 10, $this->operation);
    expect(fn () => $this->ledger->releaseRemovedKey($this->company, 'product_documents', $path))
        ->toThrow(LogicException::class, 'still exist');
    Storage::disk('product_documents')->delete($path);
    $this->ledger->releaseRemovedKey($this->company, 'product_documents', $path);
    $this->ledger->releaseRemovedKey($this->company, 'product_documents', $path);
    expect($this->ledger->usedBytes($this->company))->toBe(10);
});

test('generic orphan deletion cannot release an in-flight reservation', function () {
    $claim = $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    expect(fn () => $this->ledger->releaseRemovedKey($this->company, 'catalog_media', $this->key))
        ->toThrow(LogicException::class, 'In-flight');
    expect($this->ledger->usedBytes($this->company))->toBe(70);
    $this->ledger->releaseConfirmed($this->company, $claim);
    expect($this->ledger->usedBytes($this->company))->toBe(0);
});

test('changed disk bindings cannot release a reservation', function () {
    $claim = $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
    $disk = Storage::build(['driver' => 'local', 'root' => Storage::disk('catalog_media')->path('changed-root')]);
    Storage::disk('catalog_media')->makeDirectory('changed-root');
    Storage::set('catalog_media', $disk);
    expect(fn () => $this->ledger->releaseConfirmed($this->company, $claim))->toThrow(LogicException::class, 'binding');
    expect($this->ledger->usedBytes($this->company))->toBe(70);
});

test('a last storage allowance is fenced by tenant lock without blocking another tenant', function () {
    DB::commit();
    $foreign = Company::factory()->create(['commercial_model_version' => 1]);
    CompanySubscription::query()->create(['company_id' => $foreign->id, 'plan_key' => 'starter',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['storage.bytes.max' => 100]]);
    storageCompetingConnection();
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
        config(['database.default' => 'mysql_storage_competitor']);
        expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', $this->company->uuid.'/blocked.bin', 31, (string) Str::uuid()))
            ->toThrow(QueryException::class, 'Lock wait timeout');
        $this->ledger->reserve($foreign, 'catalog_media', $foreign->uuid.'/foreign.bin', 100, (string) Str::uuid());
        config(['database.default' => 'mysql']);
        $primary->commit();
        expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', $this->company->uuid.'/blocked.bin', 31, (string) Str::uuid()))
            ->toThrow(ValidationException::class, 'allowance');
        expect($this->ledger->usedBytes($this->company))->toBe(70)->and($this->ledger->usedBytes($foreign))->toBe(100);
    } finally {
        config(['database.default' => 'mysql']);
        while ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        foreach (DB::getConnections() as $name => $connection) {
            if (str_starts_with($name, 'commercial_storage_')) {
                $connection->table('commercial_storage_objects')->where('company_uuid', $foreign->uuid)->delete();
                $connection->table('commercial_storage_balances')->where('company_uuid', $foreign->uuid)->delete();
            }
        }
        DB::table('company_subscriptions')->where('company_id', $foreign->id)->delete();
        DB::table('companies')->where('uuid', $foreign->uuid)->delete();
        DB::disconnect('mysql_storage_competitor');
    }
});

test('a stale catalogue snapshot cannot hide bytes admitted on a competing connection', function () {
    DB::commit();
    $this->ledger->reserve($this->company, 'catalog_media', $this->company->uuid.'/zero.bin', 0, (string) Str::uuid());
    storageCompetingConnection();
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect((int) DB::table('commercial_storage_balances')->where('company_uuid', $this->company->uuid)->value('used_bytes'))->toBe(0);
        config(['database.default' => 'mysql_storage_competitor']);
        $this->ledger->reserve($this->company, 'catalog_media', $this->key, 70, $this->operation);
        config(['database.default' => 'mysql']);
        expect((int) DB::table('commercial_storage_balances')->where('company_uuid', $this->company->uuid)->value('used_bytes'))->toBe(0);
        expect(fn () => $this->ledger->reserve($this->company, 'catalog_media', $this->company->uuid.'/too-much.bin', 31, (string) Str::uuid()))
            ->toThrow(ValidationException::class, 'allowance');
        expect($this->ledger->usedBytes($this->company))->toBe(70);
    } finally {
        config(['database.default' => 'mysql']);
        $primary->rollBack();
        DB::disconnect('mysql_storage_competitor');
    }
});

test('physical aliases have identical keys and cannot reserve an already seeded object', function () {
    $documents = Storage::disk('product_documents');
    $root = $documents->path('companies');
    $documents->makeDirectory('companies');
    config(['filesystems.disks.media_alias' => ['driver' => 'local', 'root' => $root], 'catalog.media.disk' => 'media_alias']);
    Storage::set('media_alias', Storage::build(['driver' => 'local', 'root' => $root, 'throw' => true]));
    $documents->put('companies/'.$this->company->uuid.'/shared.bin', str_repeat('x', 29));
    $inventory = app(TenantStorageInventory::class);
    expect($inventory->physicalKey($this->company, 'media_alias', $this->company->uuid.'/shared.bin'))
        ->toBe($inventory->physicalKey($this->company, 'product_documents', 'companies/'.$this->company->uuid.'/shared.bin'));
    expect(fn () => $this->ledger->reserve($this->company, 'media_alias', $this->company->uuid.'/shared.bin', 29, $this->operation))
        ->toThrow(ValidationException::class);
    expect($this->ledger->usedBytes($this->company))->toBe(29);
});
