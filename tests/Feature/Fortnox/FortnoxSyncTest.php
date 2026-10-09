<?php

require_once __DIR__.'/Helpers.php';

use App\Actions\Fortnox\ResolveFortnoxConflictAction;
use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Enums\AuditEvent;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Exceptions\Catalog\ProductOperationException;
use App\Exceptions\Catalog\VariantOperationException;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\AuditLog;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryProduct;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxFieldOverride;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Illuminate\Support\Facades\Config;
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

test('fortnox cannot create a new product when catalog capacity is full', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['catalog.products.max' => 0, 'fortnox.enabled' => true],
    ]);

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company))
        ->toThrow(ValidationException::class, 'catalog.products.max');
    expect(Product::query()->forCompany($company)->count())->toBe(0)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->count())->toBe(0);
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

    $response = $this->get(route('settings.integrations.fortnox.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[@action="'.route('settings.integrations.fortnox.conflicts.resolve', $conflict).'"]');
    expect($forms->length)->toBe(2);
    foreach ($forms as $form) {
        expect($form->getAttribute('method'))->toBe('POST')
            ->and($xpath->query('.//button[@type="submit"]', $form)->length)->toBe(1);
    }

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

test('completed empty run does not prevent a later trigger from fetching new articles', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $run = FortnoxSyncRun::query()->create([
        'company_id' => $company->getKey(),
        'connection_id' => $connection->getKey(),
        'trigger_type' => FortnoxSyncTrigger::Manual,
        'status' => FortnoxSyncRunStatus::Completed,
        'idempotency_key' => str_repeat('a', 64),
        'dedupe_key' => hash('sha256', implode('|', [$connection->getKey(), 'manual', 'initial'])),
        'pagination_complete' => true,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    fortnoxFake()->seedArticles([fortnoxArticle(['number' => 'NEW-AFTER-EMPTY', 'gtin' => null])]);
    $returned = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($returned->is($run))->toBeFalse()
        ->and(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(2)
        ->and(Product::query()->forCompany($company)->count())->toBe(1);
});

test('concurrent trigger while a fresh sync is running is rejected', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    FortnoxSyncRun::query()->create([
        'company_id' => $company->getKey(),
        'connection_id' => $connection->getKey(),
        'trigger_type' => FortnoxSyncTrigger::Manual,
        'status' => FortnoxSyncRunStatus::Running,
        'idempotency_key' => str_repeat('b', 64),
        'dedupe_key' => hash('sha256', implode('|', [$connection->getKey(), 'manual', 'initial'])),
        'started_at' => now(),
        'triggered_by' => $actor->getKey(),
    ]);

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company))
        ->toThrow(ValidationException::class, 'already in progress');

    expect(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(1)
        ->and(Product::query()->forCompany($company)->count())->toBe(0);
});

test('legacy abandoned cursor without a saved maximum safely replays earlier pages', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    fortnoxFake()->setPageSize(2)->seedArticles([
        fortnoxArticle(['number' => 'A-1', 'gtin' => null, 'lastModified' => now()->subMinutes(9)->toIso8601String()]),
        fortnoxArticle(['number' => 'A-2', 'gtin' => null, 'lastModified' => now()->subMinutes(8)->toIso8601String()]),
        fortnoxArticle(['number' => 'A-3', 'gtin' => null, 'lastModified' => now()->subMinutes(7)->toIso8601String()]),
        fortnoxArticle(['number' => 'A-4', 'gtin' => null, 'lastModified' => now()->subMinutes(6)->toIso8601String()]),
    ]);

    $run = FortnoxSyncRun::query()->create([
        'company_id' => $company->getKey(),
        'connection_id' => $connection->getKey(),
        'trigger_type' => FortnoxSyncTrigger::Manual,
        'status' => FortnoxSyncRunStatus::Running,
        'idempotency_key' => str_repeat('c', 64),
        'dedupe_key' => hash('sha256', implode('|', [$connection->getKey(), 'manual', 'initial'])),
        'cursor_page' => 2,
        'started_at' => now()->subHours(2),
        'triggered_by' => $actor->getKey(),
    ]);

    $returned = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($returned->is($run))->toBeTrue()
        ->and($returned->status)->toBe(FortnoxSyncRunStatus::Completed)
        ->and($returned->pagination_complete)->toBeTrue()
        ->and($returned->articles_fetched)->toBe(4)
        ->and($connection->fresh()->last_synced_at)->not->toBeNull();
});

test('capped pagination does not advance the watermark and resumes on the next trigger', function () {
    Config::set('fortnox.sync_max_articles_per_run', 3);
    [$actor, $company, $connection] = fortnoxConnected([]);
    fortnoxFake()->setPageSize(2)->seedArticles([
        fortnoxArticle(['number' => 'C-1', 'gtin' => null, 'lastModified' => now()->subMinutes(9)->toIso8601String()]),
        fortnoxArticle(['number' => 'C-2', 'gtin' => null, 'lastModified' => now()->subMinutes(8)->toIso8601String()]),
        fortnoxArticle(['number' => 'C-3', 'gtin' => null, 'lastModified' => now()->subMinutes(7)->toIso8601String()]),
        fortnoxArticle(['number' => 'C-4', 'gtin' => null, 'lastModified' => now()->subMinutes(6)->toIso8601String()]),
        fortnoxArticle(['number' => 'C-5', 'gtin' => null, 'lastModified' => now()->subMinutes(5)->toIso8601String()]),
    ]);

    $first = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($first->status)->toBe(FortnoxSyncRunStatus::Completed)
        ->and($first->pagination_complete)->toBeFalse()
        ->and($first->cursor_page)->toBe(3)
        ->and($first->articles_fetched)->toBe(4)
        ->and($connection->fresh()->last_synced_at)->toBeNull()
        ->and(Product::query()->forCompany($company)->count())->toBe(4);

    $second = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($second->is($first))->toBeTrue()
        ->and($second->pagination_complete)->toBeTrue()
        ->and($second->cursor_page)->toBeNull()
        ->and($second->articles_fetched)->toBe(5)
        ->and($connection->fresh()->last_synced_at)->not->toBeNull()
        ->and(Product::query()->forCompany($company)->count())->toBe(5);
});

test('resumed watermark includes earlier pages even when the tail is older', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    config(['fortnox.sync_max_articles_per_run' => 1]);
    $highest = now()->subMinute()->startOfSecond();
    fortnoxFake()->setPageSize(1)->seedArticles([
        fortnoxArticle(['number' => 'WM-1', 'gtin' => null, 'lastModified' => $highest->toIso8601String()]),
        fortnoxArticle(['number' => 'WM-2', 'gtin' => null, 'lastModified' => now()->subHour()->toIso8601String()]),
    ]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($connection->fresh()->last_synced_at)->toBeNull();
    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company, FortnoxSyncTrigger::Scheduled);
    expect($connection->fresh()->last_synced_at->toIso8601String())->toBe($highest->toIso8601String())
        ->and($run->articles_fetched)->toBe(2);
});

test('conflict resolution cannot overwrite a newer local edit', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $product = Product::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'First local edit'])->save();
    fortnoxFake()->seedArticles([fortnoxArticle(['name' => 'Provider edit', 'lastModified' => now()->addMinute()->toIso8601String()])]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $conflict = FortnoxSyncConflict::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'Newer local edit'])->save();
    expect(fn () => app(ResolveFortnoxConflictAction::class)->execute($actor, $company, $conflict->uuid, 'fortnox'))
        ->toThrow(ValidationException::class);
    expect($product->fresh()->name)->toBe('Newer local edit')->and($conflict->fresh()->status)->toBe(FortnoxConflictStatus::Open);
    app(ResolveFortnoxConflictAction::class)->execute($actor, $company, $conflict->uuid, 'nordipass');
    expect(FortnoxFieldOverride::query()->forCompany($company)->sole()->manual_value['value'])->toBe('Newer local edit');
});

test('invalid GTIN on a new article is not silently acknowledged as applied', function () {
    [$actor, $company, $connection] = fortnoxConnected([fortnoxArticle(['gtin' => 'invalid-gtin'])]);
    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company))->toThrow(VariantOperationException::class);
    expect(Product::query()->forCompany($company)->count())->toBe(0)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->count())->toBe(0)
        ->and($connection->fresh()->last_synced_at)->toBeNull();
});

test('invalid catalog field does not consume a fortnox delivery or advance its watermark', function () {
    [$actor, $company, $connection] = fortnoxConnected([
        fortnoxArticle(['number' => 'RETRY-1', 'gtin' => null, 'lastModified' => now()->subHour()->toIso8601String()]),
    ]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $before = $connection->fresh()->last_synced_at;
    $mapping = FortnoxArticleMapping::query()->forCompany($company)->sole();
    $seen = $mapping->fortnox_last_modified;
    fortnoxFake()->seedArticles([
        fortnoxArticle(['number' => 'RETRY-1', 'name' => '', 'gtin' => null, 'lastModified' => now()->subMinute()->toIso8601String()]),
    ]);
    try {
        app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
        test()->fail('Rejected fields must fail the attempt for safe retry.');
    } catch (ProductOperationException $exception) {
        expect($mapping->fresh()->fortnox_last_modified->toIso8601String())->toBe($seen->toIso8601String())
            ->and($connection->fresh()->last_synced_at->toIso8601String())->toBe($before->toIso8601String());
    }
});

test('a separate physical database connection cannot acquire the sync mutex during provider IO', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $settings = config('database.connections.mysql');
    $other = new PDO('mysql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'],
        $settings['username'], $settings['password']);
    $key = hash('sha256', $settings['database'].'|fortnox-sync|'.$company->id);
    $fake = fortnoxFake();
    $provider = Mockery::mock(FortnoxProvider::class);
    $provider->shouldReceive('listArticles')->once()->andReturnUsing(function (...$arguments) use ($other, $key, $fake) {
        $statement = $other->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$key]);
        $acquired = (int) $statement->fetchColumn();
        if ($acquired === 1) {
            $release = $other->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$key]);
        }
        expect($acquired)->toBe(0);

        return $fake->listArticles(...$arguments);
    });
    app()->instance(FortnoxProvider::class, $provider);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
});

test('fortnox name and description updates preserve the product taxonomy', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $product = Product::query()->forCompany($company)->sole();
    $primary = new Category;
    $primary->forceFill([
        'company_id' => $company->getKey(),
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Primary',
        'slug' => 'primary',
        'slug_normalized' => 'primary',
        'description' => null,
        'sort_order' => 10,
        'status' => 'active',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();
    $additional = new Category;
    $additional->forceFill([
        'company_id' => $company->getKey(),
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Additional',
        'slug' => 'additional',
        'slug_normalized' => 'additional',
        'description' => null,
        'sort_order' => 20,
        'status' => 'active',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();
    $product->forceFill(['primary_category_id' => $primary->getKey()])->save();
    foreach ([$primary, $additional] as $category) {
        $assignment = new CategoryProduct;
        $assignment->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'category_id' => $category->getKey(),
        ])->save();
    }

    fortnoxFake()->seedArticles([fortnoxArticle([
        'name' => 'Nordic Lamp Renamed',
        'description' => 'Renamed by Fortnox.',
        'lastModified' => now()->addMinute()->toIso8601String(),
    ])]);

    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($run->articles_updated)->toBe(1);
    $product = $product->fresh();
    expect($product->name)->toBe('Nordic Lamp Renamed')
        ->and($product->description)->toBe('Renamed by Fortnox.')
        ->and($product->primary_category_id)->toBe($primary->getKey())
        ->and($product->categories()->pluck('categories.id')->all())->toEqualCanonicalizing([$primary->getKey(), $additional->getKey()]);
});

test('failed sync retried for the same dedupe key starts a fresh attempt', function () {
    [$actor, $company] = fortnoxConnected([fortnoxArticle()]);
    fortnoxFake()->failNext('list_articles', new FortnoxProviderException('Provider is gone', false, 404));

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company))
        ->toThrow(FortnoxProviderException::class);

    $failed = FortnoxSyncRun::query()->forCompany($company)->sole();
    expect($failed->status)->toBe(FortnoxSyncRunStatus::Failed);

    $second = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    expect($second->status)->toBe(FortnoxSyncRunStatus::Completed)
        ->and($second->dedupe_key)->toBe($failed->dedupe_key)
        ->and($second->getKey())->not->toBe($failed->getKey())
        ->and(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(2)
        ->and(Product::query()->forCompany($company)->count())->toBe(1);
});
