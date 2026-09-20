<?php

require_once __DIR__.'/Helpers.php';

use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Models\Catalog\Product;
use App\Models\Fortnox\FortnoxSyncRun;
use Illuminate\Support\Facades\Schedule;

function fortnoxScheduledArticle(array $overrides = []): array
{
    return array_merge([
        'id' => 'scheduled-1',
        'number' => 'SCHED-100',
        'name' => 'Scheduled Lamp',
        'description' => 'Synced by the scheduler.',
        'gtin' => '7312345678916',
        'price' => 249.5,
        'stock' => 7,
        'lastModified' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);
}

test('scheduled fortnox command syncs active connections', function () {
    [$actor, $company] = fortnoxConnected([fortnoxScheduledArticle()]);

    $this->artisan('fortnox:sync --all-companies --format=json')
        ->assertExitCode(0);

    $run = FortnoxSyncRun::query()->forCompany($company)->sole();
    expect($run->trigger_type)->toBe(FortnoxSyncTrigger::Scheduled)
        ->and($run->status)->toBe(FortnoxSyncRunStatus::Completed)
        ->and($run->triggered_by)->toBe($actor->getKey())
        ->and($run->articles_fetched)->toBe(1)
        ->and($run->articles_created)->toBe(1);

    expect(Product::query()->forCompany($company)->sole()->name)->toBe('Scheduled Lamp');
});

test('scheduled fortnox command can target one company', function () {
    [, $company] = fortnoxConnected([fortnoxScheduledArticle(['number' => 'SCHED-ONE'])]);
    [, $otherCompany] = fortnoxConnected([fortnoxScheduledArticle(['number' => 'SCHED-TWO'])]);

    $this->artisan('fortnox:sync', ['--company' => $company->uuid, '--format' => 'json'])
        ->assertExitCode(0);

    expect(FortnoxSyncRun::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxSyncRun::query()->forCompany($otherCompany)->count())->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(1)
        ->and(Product::query()->forCompany($otherCompany)->count())->toBe(0);
});

test('scheduled fortnox command noops when feature flag is disabled', function () {
    fortnoxConnected([fortnoxScheduledArticle()]);
    config(['features.fortnox_integration' => false]);

    $this->artisan('fortnox:sync --all-companies')
        ->expectsOutput('Fortnox integration is disabled; scheduled sync skipped.')
        ->assertExitCode(0);

    expect(FortnoxSyncRun::query()->count())->toBe(0);
});

test('scheduled fortnox command skips connections without a connected user', function () {
    [, , $connection] = fortnoxConnected([fortnoxScheduledArticle()]);
    $connection->forceFill(['connected_by' => null])->save();

    $this->artisan('fortnox:sync --all-companies --format=json')
        ->assertExitCode(0);

    expect(FortnoxSyncRun::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0);
});

test('fortnox scheduled sync is registered hourly', function () {
    $events = Schedule::events();
    $count = 0;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'fortnox:sync --all-companies')) {
            $count++;
            expect($event->expression)->toBe('0 * * * *');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);
        }
    }

    expect($count)->toBe(1);
});
