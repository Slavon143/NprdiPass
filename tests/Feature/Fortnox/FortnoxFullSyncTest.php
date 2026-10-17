<?php

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../../Fixtures/FortnoxOAuthTokens.php';

use App\Actions\Fortnox\PreviewFortnoxFullSyncAction;
use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Data\Fortnox\FortnoxArticlePage;
use App\Enums\CompanyRole;
use App\Enums\Fortnox\FortnoxSyncMode;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Exceptions\Fortnox\FortnoxSubscriptionBlocked;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use App\Services\Fortnox\FakeFortnoxProvider;
use App\Services\Fortnox\HttpFortnoxProvider;
use App\Support\Fortnox\FortnoxSyncPresenter;
use App\Tenancy\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fullSyncArticle(string $number, ?string $modified = null): array
{
    return ['id' => $number, 'number' => $number, 'name' => 'Test '.$number, 'price' => 0,
        'lastModified' => $modified ?? now()->subDay()->toIso8601String()];
}

test('zero incremental results explain no changes and full preview imports older unmapped articles', function () {
    [$actor, $company, $connection] = fortnoxConnected([fullSyncArticle('EXISTING', now()->subHour()->toIso8601String())]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $baseline = $connection->fresh()->last_synced_at->toIso8601String();
    $mappingBefore = FortnoxArticleMapping::query()->forCompany($company)->sole()->getAttributes();
    fortnoxFake()->setPageSize(1)->addArticles([fullSyncArticle('OLDER')]);
    $incremental = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($incremental->articles_fetched)->toBe(0)->and($incremental->sync_mode)->toBe(FortnoxSyncMode::Incremental)
        ->and(app(FortnoxSyncPresenter::class)->message($incremental))->toContain('No changes since', 'does not mean');

    $response = $this->post(route('settings.integrations.fortnox.full-preview'), ['company_id' => 999999, 'tenant_id' => '1853390'])->assertOk();
    $preview = $response->viewData('preview');
    expect($preview['total_articles'])->toBe(2)->and($preview['unmapped_articles'])->toBe(1)
        ->and($preview['pages_fetched'])->toBe(2)
        ->and(Product::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxArticleMapping::query()->forCompany($company)->sole()->getAttributes())->toEqual($mappingBefore)
        ->and($connection->fresh()->last_synced_at->toIso8601String())->toBe($baseline);
    $this->post(route('settings.integrations.fortnox.full-import'), ['preview_token' => $preview['token']])->assertRedirect()
        ->assertSessionHas('success');
    $full = FortnoxSyncRun::query()->forCompany($company)->latest('id')->firstOrFail();
    expect($full->sync_mode)->toBe(FortnoxSyncMode::Full)->and($full->pages_fetched)->toBe(2)
        ->and($full->articles_created)->toBe(1)->and($full->articles_skipped)->toBe(1)
        ->and(Product::query()->forCompany($company)->count())->toBe(2)
        ->and($connection->fresh()->last_synced_at->toIso8601String())->toBe($baseline);
    $this->get(route('settings.integrations.fortnox.index'))->assertOk()->assertSee('Full catalogue scan')->assertSee('Changes since watermark');
    Http::assertNothingSent();
});

test('capped full scan keeps its mode and advances the watermark only after the final page', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $baseline = CarbonImmutable::now()->subHour()->startOfSecond();
    $latest = CarbonImmutable::now()->startOfSecond();
    $connection->forceFill(['last_synced_at' => $baseline])->save();
    config(['fortnox.sync_max_articles_per_run' => 2]);
    fortnoxFake()->setPageSize(1)->seedArticles([fullSyncArticle('OLD-1'), fullSyncArticle('OLD-2'), fullSyncArticle('NEW-3', $latest->toIso8601String())]);
    $action = app(SyncFortnoxArticlesAction::class);
    $partial = $action->execute($actor, $company, mode: FortnoxSyncMode::Full);
    expect($partial->pagination_complete)->toBeFalse()->and($partial->cursor_page)->toBe(3)
        ->and($partial->pages_fetched)->toBe(2)->and($connection->fresh()->last_synced_at->equalTo($baseline))->toBeTrue()
        ->and(app(FortnoxSyncPresenter::class)->message($partial))->toContain('incomplete', 'page 3');
    $completed = $action->execute($actor, $company, FortnoxSyncTrigger::Scheduled);
    expect($completed->id)->toBe($partial->id)->and($completed->sync_mode)->toBe(FortnoxSyncMode::Full)
        ->and($completed->pages_fetched)->toBe(3)->and($completed->articles_fetched)->toBe(3)
        ->and($completed->pagination_complete)->toBeTrue()->and($connection->fresh()->last_synced_at->equalTo($latest))->toBeTrue();
});

test('failed full scan preserves the watermark and retry scans all articles without clearing mappings', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $baseline = CarbonImmutable::now()->subHour()->startOfSecond();
    $connection->forceFill(['last_synced_at' => $baseline])->save();
    $provider = new class extends FakeFortnoxProvider
    {
        public bool $failedOnce = false;

        public function listArticles(string $accessToken, ?string $modifiedAfter = null, ?string $page = null): FortnoxArticlePage
        {
            if ($page === '2' && ! $this->failedOnce) {
                $this->failedOnce = true;
                throw new FortnoxProviderException('Test page failure.', false);
            }

            return parent::listArticles($accessToken, $modifiedAfter, $page);
        }
    };
    $provider->setPageSize(1)->seedArticles([fullSyncArticle('FAIL-1'), fullSyncArticle('FAIL-2')]);
    app()->instance(FortnoxProvider::class, $provider);
    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company, mode: FortnoxSyncMode::Full))->toThrow(FortnoxProviderException::class);
    expect($connection->fresh()->last_synced_at->equalTo($baseline))->toBeTrue();
    $mappingBefore = FortnoxArticleMapping::query()->forCompany($company)->sole();
    $applied = $mappingBefore->last_applied_values;
    $retry = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($retry->sync_mode)->toBe(FortnoxSyncMode::Full)->and($retry->pagination_complete)->toBeTrue()
        ->and($retry->articles_created)->toBe(1)->and($retry->articles_skipped)->toBe(1)
        ->and($mappingBefore->fresh()->last_applied_values)->toEqual($applied)
        ->and(Product::query()->forCompany($company)->count())->toBe(2);
    $incremental = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($incremental->sync_mode)->toBe(FortnoxSyncMode::Incremental)->and($incremental->articles_fetched)->toBe(0);
});

test('full scan keeps conflict detection instead of erasing applied values', function () {
    [$actor, $company] = fortnoxConnected([fullSyncArticle('CONFLICT')]);
    app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    $product = Product::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'Locally renamed'])->save();
    fortnoxFake()->seedArticles([array_replace(fullSyncArticle('CONFLICT', now()->toIso8601String()), ['name' => 'Remote rename'])]);
    $run = app(SyncFortnoxArticlesAction::class)->execute($actor, $company, mode: FortnoxSyncMode::Full);
    expect($run->conflicts_raised)->toBe(1)->and($product->fresh()->name)->toBe('Locally renamed');
});

test('preview confirmation refuses stale watermark expired and replayed tokens', function (string $fault) {
    [$actor, $company, $connection] = fortnoxConnected([fullSyncArticle('PREVIEW')]);
    $preview = app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company);
    if ($fault === 'watermark') {
        $connection->forceFill(['last_synced_at' => now()])->save();
    } elseif ($fault === 'expired') {
        $this->travel(11)->minutes();
    } else {
        app(PreviewFortnoxFullSyncAction::class)->consume($actor, $company, $preview['token']);
    }
    $this->post(route('settings.integrations.fortnox.full-import'), ['preview_token' => $preview['token']])->assertRedirect()->assertSessionHas('error');
    expect(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(0);
    Http::assertNothingSent();
})->with(['watermark', 'expired', 'replayed']);

test('another company cannot consume a preview or apply its articles', function () {
    [$actor, $company] = fortnoxConnected([fullSyncArticle('OWNED')]);
    $preview = app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company);
    $other = Company::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $other->id, 'user_id' => $actor->id, 'role' => CompanyRole::Owner]);
    app(CurrentCompany::class)->set($other);
    $this->post(route('settings.integrations.fortnox.full-import'), ['preview_token' => $preview['token'], 'company_id' => $company->id])->assertRedirect()->assertSessionHas('error');
    expect(Product::query()->count())->toBe(0);
    app(CurrentCompany::class)->set($company);
    expect(app(PreviewFortnoxFullSyncAction::class)->consume($actor, $company, $preview['token'])['connection_uuid'])->toBeString();
});

test('preview and confirmation recheck company permissions', function () {
    [$actor, $company] = fortnoxConnected([fullSyncArticle('PERMISSION')]);
    $preview = app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company);
    CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $actor->id)->update(['role' => CompanyRole::Viewer]);
    $this->post(route('settings.integrations.fortnox.full-preview'))->assertForbidden();
    $this->post(route('settings.integrations.fortnox.full-import'), ['preview_token' => $preview['token']])->assertForbidden();
    expect(FortnoxSyncRun::query()->count())->toBe(0);
});

test('full preview enforces its safety cap even when the oversized response is the last page', function () {
    [$actor, $company] = fortnoxConnected([fullSyncArticle('CAP-1'), fullSyncArticle('CAP-2'), fullSyncArticle('CAP-3')]);
    config(['fortnox.reconciliation.max_articles' => 2]);
    expect(fn () => app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company))->toThrow(FortnoxProviderException::class, 'safety cap exceeded');
    expect(Product::query()->count())->toBe(0)->and(FortnoxSyncRun::query()->count())->toBe(0);
});

test('new full preview and import refuse production before provider or catalogue work', function () {
    [$actor, $company] = fortnoxConnected([fullSyncArticle('PRODUCTION-BLOCKED')]);
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company))->toThrow(ValidationException::class, 'test environment');
    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company, mode: FortnoxSyncMode::Full))->toThrow(ValidationException::class, 'test environment');
    expect(FortnoxSyncRun::query()->count())->toBe(0)->and(Product::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('preview rejects production other and unidentified real Fortnox accounts without network requests', function (?string $tenant) {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $connection->forceFill(['external_tenant_id' => $tenant,
        'token_payload' => ['access_token' => $tenant === null ? 'opaque-fixture' : fortnoxTenantJwt($tenant)],
        'token_expires_at' => now()->addHour()])->save();
    app()->instance(FortnoxProvider::class, new HttpFortnoxProvider);
    expect(fn () => app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company))->toThrow(FortnoxSubscriptionBlocked::class);
    Http::assertNothingSent();
    expect(FortnoxSyncRun::query()->count())->toBe(0);
})->with(['1853390', '9999999', null]);

test('legacy unknown scans do not invent a full filter or a page count', function () {
    $run = (new FortnoxSyncRun)->forceFill(['status' => 'completed', 'pagination_complete' => true, 'articles_fetched' => 0]);
    expect(app(FortnoxSyncPresenter::class)->message($run))->toContain('historical scan', 'not recorded');
});

test('full preview bounds pagination independently of article counts', function () {
    [$actor, $company] = fortnoxConnected([fullSyncArticle('PAGE-1'), fullSyncArticle('PAGE-2')]);
    fortnoxFake()->setPageSize(1);
    config(['fortnox.reconciliation.max_pages' => 1]);
    expect(fn () => app(PreviewFortnoxFullSyncAction::class)->execute($actor, $company))->toThrow(FortnoxProviderException::class, '1 pages');
    expect(Product::query()->count())->toBe(0);
});

test('a capped empty first page resumes its recorded cursor without restarting the full scan', function () {
    [$actor, $company, $connection] = fortnoxConnected([]);
    $provider = new class extends FakeFortnoxProvider
    {
        public function listArticles(string $accessToken, ?string $modifiedAfter = null, ?string $page = null): FortnoxArticlePage
        {
            return $page === null ? new FortnoxArticlePage([], '2') : parent::listArticles($accessToken, $modifiedAfter);
        }
    };
    $provider->seedArticles([fullSyncArticle('AFTER-EMPTY-PAGE')]);
    app()->instance(FortnoxProvider::class, $provider);
    config(['fortnox.sync_max_pages_per_run' => 1]);
    $partial = app(SyncFortnoxArticlesAction::class)->execute($actor, $company, mode: FortnoxSyncMode::Full);
    expect($partial->pages_fetched)->toBe(1)->and($partial->cursor_page)->toBe(2)
        ->and($partial->watermark_observed)->toBeNull()->and($connection->fresh()->last_synced_at)->toBeNull();
    $completed = app(SyncFortnoxArticlesAction::class)->execute($actor, $company);
    expect($completed->id)->toBe($partial->id)->and($completed->pagination_complete)->toBeTrue()
        ->and($completed->pages_fetched)->toBe(2)->and($completed->articles_created)->toBe(1);
});
