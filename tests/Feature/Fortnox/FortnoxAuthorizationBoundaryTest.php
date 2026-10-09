<?php

require_once __DIR__.'/Helpers.php';

use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Data\Fortnox\FortnoxArticlePage;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Models\Catalog\Product;
use App\Models\CompanyMembership;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Illuminate\Auth\Access\AuthorizationException;

test('Fortnox rejects rights revoked during provider I O even when the returned page is empty', function (bool $emptyPage) {
    [$actor, $company, $connection] = fortnoxConnected($emptyPage ? [] : [[
        'id' => 'boundary', 'number' => 'BOUNDARY', 'name' => 'Denied',
        'lastModified' => now()->toIso8601String(),
    ]]);
    $fake = fortnoxFake();
    $provider = Mockery::mock(FortnoxProvider::class);
    $provider->shouldReceive('listArticles')->once()->andReturnUsing(function (string $accessToken, ?string $modifiedAfter, ?string $page) use ($actor, $company, $fake): FortnoxArticlePage {
        CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $actor->id)
            ->update(['role' => 'editor', 'is_owner' => false]);

        return $fake->listArticles($accessToken, $modifiedAfter, $page);
    });
    app()->instance(FortnoxProvider::class, $provider);

    expect(fn () => app(SyncFortnoxArticlesAction::class)->execute($actor, $company))
        ->toThrow(AuthorizationException::class);
    $run = FortnoxSyncRun::query()->forCompany($company)->sole();
    expect($run->status)->toBe(FortnoxSyncRunStatus::Failed)
        ->and($run->articles_fetched)->toBe(0)
        ->and($run->watermark_observed)->toBeNull()
        ->and($run->pagination_complete)->toBeFalse()
        ->and($connection->fresh()->last_synced_at)->toBeNull()
        ->and(Product::query()->forCompany($company)->count())->toBe(0);
})->with(['empty page' => true, 'article page' => false]);
