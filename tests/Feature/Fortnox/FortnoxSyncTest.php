<?php

require_once __DIR__.'/Helpers.php';

use App\Actions\Fortnox\ResolveFortnoxConflictAction;
use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxFieldOverride;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\Fortnox\FortnoxSyncRun;
use Illuminate\Validation\ValidationException;

function fortnoxArticle(array $overrides = []): array
{
    return array_merge([
        'id' => '1',
        'number' => 'A-100',
        'name' => 'Nordic Lamp',
        'description' => 'A bright lamp.',
        'gtin' => '7312345678909',
        'price' => 199.5,
        'stock' => 12,
        'lastModified' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);
}

test('manual sync creates products variants sku and mappings from fortnox articles', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($run->status)->toBe(FortnoxSyncRunStatus::Completed)
        ->and($run->articles_fetched)->toBe(1)
        ->and($run->articles_created)->toBe(1)
        ->and($run->articles_updated)->toBe(0);

    $product = Product::query()->forCompany($company)->sole();
    $variant = ProductVariant::query()->forCompany($company)->sole();
    expect($product->name)->toBe('Nordic Lamp')
        ->and($product->description)->toBe('A bright lamp.')
        ->and($product->commerce['sales_price'] ?? null)->toBe('199.50')
        ->and($product->commerce['currency'] ?? null)->toBe('SEK')
        ->and($product->commerce['vat_code'] ?? null)->toBe('SE25')
        ->and($product->commerce['source'] ?? null)->toBe('Fortnox')
        ->and($product->commerce['last_synced_at'] ?? null)->not->toBeNull()
        ->and($variant->sku)->toBe('A-100')
        ->and($variant->gtin)->toBe('7312345678909')
        ->and($product->status->value)->toBe('draft');

    $mapping = FortnoxArticleMapping::query()->forCompany($company)->sole();
    expect($mapping->product_id)->toBe($product->getKey())
        ->and($mapping->fortnox_last_modified)->not->toBeNull()
        ->and($mapping->last_applied_values['name'] ?? null)->toBe('Nordic Lamp')
        ->and($mapping->last_applied_values['price'] ?? null)->toBe('199.50');

    expect(AuditLog::query()->where('company_id', $company->getKey())
        ->where('event', AuditEvent::FortnoxSyncCompleted->value)->exists())->toBeTrue();
});

test('sync maps by existing sku instead of creating duplicates', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    $existing = new Product;
    $existing->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Existing',
        'slug' => 'existing',
        'slug_normalized' => 'existing',
        'status' => ProductStatus::Draft,
        'default_variant_id' => null,
    ])->save();
    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $existing->getKey(),
        'name' => 'Default',
        'sku' => 'A-100',
        'sku_normalized' => 'A-100',
        'status' => ProductVariantStatus::Draft,
        'sort_order' => 0,
    ])->save();

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($run->articles_created)->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->sole()->product_id)->toBe($existing->getKey());
});

test('unchanged articles are skipped idempotently on repeat syncs', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $second = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($second->articles_fetched)->toBe(0)
        ->and($second->articles_skipped)->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(2);
});

test('changed fortnox fields update the catalog when no local edits exist', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    fortnoxFake()->seedArticles([fortnoxArticle([
        'name' => 'Nordic Lamp Pro',
        'description' => 'An even brighter lamp.',
        'price' => 249.5,
        'lastModified' => now()->addMinute()->toIso8601String(),
    ])]);

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($run->articles_updated)->toBe(1);
    $product = Product::query()->forCompany($company)->sole();
    expect($product->name)->toBe('Nordic Lamp Pro')
        ->and($product->description)->toBe('An even brighter lamp.')
        ->and($product->commerce['sales_price'] ?? null)->toBe('249.50');
});

test('fortnox price clearing removes the product commerce sales price', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    fortnoxFake()->seedArticles([fortnoxArticle([
        'price' => null,
        'lastModified' => now()->addMinute()->toIso8601String(),
    ])]);

    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $product = Product::query()->forCompany($company)->sole();
    expect(array_key_exists('sales_price', $product->commerce))->toBeTrue()
        ->and($product->commerce['sales_price'])->toBeNull()
        ->and($product->commerce['source'] ?? null)->toBe('Fortnox');
});

test('both sides changed raises a conflict without writing the catalog', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $product = Product::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'Locally Renamed'])->save();
    fortnoxFake()->seedArticles([fortnoxArticle([
        'name' => 'Fortnox Renamed',
        'lastModified' => now()->addMinute()->toIso8601String(),
    ])]);

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($run->conflicts_raised)->toBe(1)->and($run->articles_updated)->toBe(0);
    expect($product->fresh()->name)->toBe('Locally Renamed');

    $conflict = FortnoxSyncConflict::query()->forCompany($company)->sole();
    expect($conflict->status)->toBe(FortnoxConflictStatus::Open)
        ->and($conflict->field->value)->toBe('name')
        ->and($conflict->fortnox_value['value'] ?? null)->toBe('Fortnox Renamed')
        ->and($conflict->nordipass_value['value'] ?? null)->toBe('Locally Renamed');

    $next = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect(FortnoxSyncConflict::query()->forCompany($company)->count())->toBe(1)
        ->and($next->conflicts_raised)->toBe(0);
});

test('resolving a conflict for fortnox applies the provider value', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $product = Product::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'Locally Renamed'])->save();
    fortnoxFake()->seedArticles([fortnoxArticle([
        'name' => 'Fortnox Renamed',
        'lastModified' => now()->addMinute()->toIso8601String(),
    ])]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $conflict = FortnoxSyncConflict::query()->forCompany($company)->sole();
    $resolved = app(ResolveFortnoxConflictAction::class)->execute($actor, $company, $conflict->uuid, 'fortnox');

    expect($resolved->status)->toBe(FortnoxConflictStatus::ResolvedFortnox)
        ->and($resolved->resolved_at)->not->toBeNull();
    expect($product->fresh()->name)->toBe('Fortnox Renamed');
    expect(FortnoxFieldOverride::query()->forCompany($company)->exists())->toBeFalse();

    $audit = AuditLog::query()->where('company_id', $company->getKey())
        ->where('event', AuditEvent::FortnoxConflictResolved->value)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->getProperty('resolution'))->toBe('fortnox');
});

test('resolving a conflict for nordipass records an override that future syncs respect', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $product = Product::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'Locally Renamed'])->save();
    fortnoxFake()->seedArticles([fortnoxArticle([
        'name' => 'Fortnox Renamed',
        'lastModified' => now()->addMinute()->toIso8601String(),
    ])]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $conflict = FortnoxSyncConflict::query()->forCompany($company)->sole();
    $resolved = app(ResolveFortnoxConflictAction::class)->execute($actor, $company, $conflict->uuid, 'nordipass');

    expect($resolved->status)->toBe(FortnoxConflictStatus::ResolvedNordipass);
    $override = FortnoxFieldOverride::query()->forCompany($company)->sole();
    expect($override->source)->toBe('nordipass')->and($override->field->value)->toBe('name');
    expect($product->fresh()->name)->toBe('Locally Renamed');

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($run->articles_updated)->toBe(0);
    expect($product->fresh()->name)->toBe('Locally Renamed');
});

test('retryable provider failures are retried with bounded attempts', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    fortnoxFake()->failNext('list_articles', FortnoxProviderException::retryable('Simulated rate limit', 429));
    fortnoxFake()->failNext('list_articles', FortnoxProviderException::retryable('Simulated timeout', 503));

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($run->status)->toBe(FortnoxSyncRunStatus::Completed)
        ->and($run->attempts)->toBe(3)
        ->and(Product::query()->forCompany($company)->count())->toBe(1);
});

test('terminal provider failures fail the run and audit the failure', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    fortnoxFake()->failNext('list_articles', new FortnoxProviderException('Provider is gone', false, 404));

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company))
        ->toThrow(FortnoxProviderException::class);

    $run = FortnoxSyncRun::query()->forCompany($company)->sole();
    expect($run->status)->toBe(FortnoxSyncRunStatus::Failed)
        ->and($run->attempts)->toBe(1)
        ->and($run->failure_message)->toContain('Provider is gone')
        ->and(Product::query()->forCompany($company)->count())->toBe(0);

    expect(AuditLog::query()->where('company_id', $company->getKey())
        ->where('event', AuditEvent::FortnoxSyncFailed->value)->exists())->toBeTrue();
});

test('expired tokens are refreshed before sync and unauthenticated refresh marks reconnect required', function () {
    [$actor, $company, $connection] = fortnoxConnected([fortnoxArticle()]);
    $connection->forceFill(['token_expires_at' => now()->subMinute()])->save();

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($run->status)->toBe(FortnoxSyncRunStatus::Completed);
    expect($connection->fresh()->token_expires_at->greaterThan(now()))->toBeTrue();

    app('auth')->forgetGuards();
    [$actor2, $company2, $connection2] = fortnoxConnected([fortnoxArticle()]);
    $connection2->forceFill(['token_expires_at' => now()->subMinute()])->save();
    fortnoxFake()->failNext('refresh', FortnoxProviderException::unauthorized('Refresh token revoked'));

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor2, $company2))
        ->toThrow(FortnoxProviderException::class);

    expect($connection2->fresh()->status)->toBe(FortnoxConnectionStatus::ReconnectRequired)
        ->and($connection2->fresh()->failure_message)->toContain('Refresh token revoked');

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor2, $company2))
        ->toThrow(ValidationException::class);
});

test('completed run idempotency key returns the existing run for duplicate triggers', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $run = FortnoxSyncRun::query()->create([
        'company_id' => $company->getKey(),
        'connection_id' => $connection->getKey(),
        'trigger_type' => FortnoxSyncTrigger::Manual,
        'status' => FortnoxSyncRunStatus::Completed,
        'idempotency_key' => hash('sha256', implode('|', [$connection->getKey(), 'manual', 'initial'])),
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $returned = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($returned->is($run))->toBeTrue()
        ->and(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(1);
});
