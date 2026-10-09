<?php

require_once __DIR__.'/Helpers.php';

use App\Actions\Fortnox\ReconcileFortnoxAction;
use App\Actions\Fortnox\ResyncFortnoxAction;
use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxSyncRun;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

function reconciliationArticle(array $overrides = []): array
{
    return array_merge([
        'id' => '1',
        'number' => 'REC-100',
        'name' => 'Reconciled Lamp',
        'description' => null,
        'gtin' => null,
        'price' => 100,
        'stock' => 0,
        'lastModified' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);
}

test('reconciliation reports missing and orphaned mappings without mutating', function () {
    [$actor, $company] = fortnoxConnected([
        reconciliationArticle(['number' => 'REC-A']),
        reconciliationArticle(['number' => 'REC-B']),
    ]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    // REC-B disappears in Fortnox, REC-C appears without a mapping.
    fortnoxFake()->seedArticles([
        reconciliationArticle(['number' => 'REC-A']),
        reconciliationArticle(['number' => 'REC-C']),
    ]);

    $report = app(ReconcileFortnoxAction::class)->execute($actor, $company);

    expect($report->complete)->toBeTrue()
        ->and($report->totalArticles)->toBe(2)
        ->and($report->missingMappingArticleNumbers)->toBe(['REC-C'])
        ->and($report->orphanedMappingArticleNumbers)->toBe(['REC-B'])
        ->and($report->archivedProducts)->toBe(0)
        ->and(Product::query()->forCompany($company)->where('status', ProductStatus::Archived->value)->count())->toBe(0)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->count())->toBe(2);
});

test('repair creates mappings only for fortnox articles matching an existing variant sku', function () {
    [$actor, $company] = fortnoxConnected([]);
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Repairable',
        'slug' => 'repairable',
        'slug_normalized' => 'repairable',
        'status' => ProductStatus::Draft,
        'default_variant_id' => null,
    ])->save();
    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'sku' => 'REC-MATCH',
        'sku_normalized' => 'REC-MATCH',
        'status' => ProductVariantStatus::Draft,
        'sort_order' => 0,
    ])->save();

    fortnoxFake()->seedArticles([
        reconciliationArticle(['number' => 'REC-MATCH']),
        reconciliationArticle(['number' => 'REC-NOMATCH']),
    ]);

    $report = app(ReconcileFortnoxAction::class)->execute($actor, $company, repairMissingMappings: true);

    expect($report->repairedArticleNumbers)->toBe(['REC-MATCH'])
        ->and($report->missingMappingArticleNumbers)->toContain('REC-NOMATCH')
        ->and(FortnoxArticleMapping::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->sole()->product_id)->toBe($product->getKey())
        ->and(Product::query()->forCompany($company)->count())->toBe(1);
});

test('archive deletion policy archives products whose fortnox articles disappeared', function () {
    [$actor, $company] = fortnoxConnected([
        reconciliationArticle(['number' => 'DEL-A']),
        reconciliationArticle(['number' => 'DEL-B']),
    ]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $kept = FortnoxArticleMapping::query()->forCompany($company)->where('fortnox_article_number', 'DEL-A')->sole()->product;
    $orphanedProduct = FortnoxArticleMapping::query()->forCompany($company)->where('fortnox_article_number', 'DEL-B')->sole()->product;
    fortnoxFake()->seedArticles([reconciliationArticle(['number' => 'DEL-A'])]);

    $report = app(ReconcileFortnoxAction::class)->execute($actor, $company, deletionPolicy: 'archive');

    expect($report->orphanedMappingArticleNumbers)->toBe(['DEL-B'])
        ->and($report->archivedProducts)->toBe(1)
        ->and($kept->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($orphanedProduct->fresh()->status)->toBe(ProductStatus::Archived);
});

test('report deletion policy leaves orphaned products untouched', function () {
    [$actor, $company] = fortnoxConnected([
        reconciliationArticle(['number' => 'DEL-C']),
        reconciliationArticle(['number' => 'DEL-D']),
    ]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    fortnoxFake()->seedArticles([reconciliationArticle(['number' => 'DEL-C'])]);

    $report = app(ReconcileFortnoxAction::class)->execute($actor, $company);

    expect($report->archivedProducts)->toBe(0)
        ->and(Product::query()->forCompany($company)->where('status', ProductStatus::Archived->value)->count())->toBe(0);
});

test('reconciliation safety cap yields an incomplete report and refuses mutations', function () {
    Config::set('fortnox.reconciliation.max_articles', 2);
    [$actor, $company] = fortnoxConnected([]);
    fortnoxFake()->setPageSize(1)->seedArticles([
        reconciliationArticle(['number' => 'CAP-1']),
        reconciliationArticle(['number' => 'CAP-2']),
        reconciliationArticle(['number' => 'CAP-3']),
    ]);

    $report = app(ReconcileFortnoxAction::class)->execute($actor, $company, deletionPolicy: 'archive');

    expect($report->complete)->toBeFalse()
        ->and($report->archivedProducts)->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(0);
});

test('resync watermark mode clears the committed watermark only', function () {
    [$actor, $company, $connection] = fortnoxConnected([reconciliationArticle(['number' => 'RS-1'])]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($connection->fresh()->last_synced_at)->not->toBeNull();
    $mapping = FortnoxArticleMapping::query()->forCompany($company)->sole();
    expect($mapping->fortnox_last_modified)->not->toBeNull();

    app(ResyncFortnoxAction::class)->execute($actor, $company, 'watermark');

    expect($connection->fresh()->last_synced_at)->toBeNull()
        ->and($mapping->fresh()->fortnox_last_modified)->not->toBeNull();
});

test('resync full mode clears the watermark and applied article state', function () {
    [$actor, $company, $connection] = fortnoxConnected([reconciliationArticle(['number' => 'RS-2'])]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $mapping = FortnoxArticleMapping::query()->forCompany($company)->sole();
    expect($mapping->fortnox_last_modified)->not->toBeNull()
        ->and($mapping->last_applied_values)->not->toBeNull();

    app(ResyncFortnoxAction::class)->execute($actor, $company, 'full');

    expect($connection->fresh()->last_synced_at)->toBeNull()
        ->and($mapping->fresh()->fortnox_last_modified)->toBeNull()
        ->and($mapping->fresh()->last_applied_values)->toBeNull();
});

test('resync refuses while a fresh sync run is in progress', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    FortnoxSyncRun::query()->create([
        'company_id' => $company->getKey(),
        'connection_id' => $connection->getKey(),
        'trigger_type' => 'manual',
        'status' => FortnoxSyncRunStatus::Running,
        'idempotency_key' => str_repeat('d', 64),
        'started_at' => now(),
        'triggered_by' => $actor->getKey(),
    ]);

    expect(fn () => app(ResyncFortnoxAction::class)->execute($actor, $company))
        ->toThrow(ValidationException::class, 'in progress');
});

test('reconcile and resync commands execute for a company', function () {
    [$actor, $company] = fortnoxConnected([reconciliationArticle(['number' => 'CMD-1'])]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);

    $this->artisan('fortnox:reconcile', ['--company' => $company->uuid, '--format' => 'json'])
        ->assertExitCode(0);

    $this->artisan('fortnox:resync', ['--company' => $company->uuid, '--mode' => 'full'])
        ->assertExitCode(2);

    $this->artisan('fortnox:resync', ['--company' => $company->uuid, '--mode' => 'full', '--confirm' => true])
        ->assertExitCode(0);
});
