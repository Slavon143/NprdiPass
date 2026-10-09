<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

test('a SQL release waiting inside its trigger applies after unknown counter backfill without losing the delta', function (string $change) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Pending release']);
    expect($company->catalogCapacity()->value('products_count'))->toBeNull();
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_DATABASE' => $db['database'], 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array',
        'BILLING_PROVIDER' => 'fake', 'FORTNOX_PROVIDER' => 'fake', 'LOG_CHANNEL' => 'null',
        'LARAVEL_STORAGE_PATH' => storage_path(), 'APP_CONFIG_CACHE' => env('APP_CONFIG_CACHE')];
    $writer = new Process([PHP_BINARY, base_path('tests/Fixtures/catalog-capacity-writer.php'),
        (string) $company->id, (string) $product->id, $change], base_path(), $environment);
    $writer->setTimeout(20);
    $monitor = new PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['database'],
        $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $started = false;
    $observed = false;
    DB::listen(function ($query) use ($writer, $monitor, $db, &$started, &$observed): void {
        if ($started || ! str_contains($query->sql, 'from `catalog_product_capacities`') || ! str_contains($query->sql, 'for update')) {
            return;
        }
        $started = true;
        $writer->start();
        $deadline = microtime(true) + 10;
        do {
            $output = $writer->getOutput();
            if (str_contains($output, "\n")) {
                $connectionId = json_decode(explode("\n", $output)[0], true, flags: JSON_THROW_ON_ERROR)['ready_connection'];
                $statement = $monitor->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
                    JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID
                    JOIN performance_schema.threads t ON t.THREAD_ID=r.THREAD_ID
                    WHERE r.OBJECT_SCHEMA=? AND t.PROCESSLIST_ID=? AND b.OBJECT_NAME=?');
                $statement->execute([$db['database'], $connectionId, 'catalog_product_capacities']);
                if ((int) $statement->fetchColumn() > 0) {
                    $observed = true;

                    return;
                }
            }
            usleep(20000);
        } while ($writer->isRunning() && microtime(true) < $deadline);
        throw new RuntimeException('Did not observe the SQL writer waiting at the capacity trigger mutex.');
    });

    try {
        $result = app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
        expect($observed)->toBeTrue()->and($result)->toMatchArray(['previous_count' => null, 'actual_count' => 1, 'repaired' => true]);
        expect($writer->wait())->toBe(0, $writer->getErrorOutput());
        expect($writer->getOutput())->toContain('DONE');
        expect($company->catalogCapacity()->value('products_count'))->toBe(0)->and($company->products()->whereIn('status', ['draft', 'active'])->count())->toBe(0);
        expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 0, 'actual_count' => 0, 'repaired' => false]);
    } finally {
        if ($writer->isRunning()) {
            $writer->stop();
        }
    }
})->with(['archive', 'soft-delete']);
