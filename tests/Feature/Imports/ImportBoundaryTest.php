<?php

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Actions\Imports\StartImportJobAction;
use App\Enums\CompanyRole;
use App\Enums\Imports\ImportMode;
use App\Jobs\Imports\ApplyImportJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Jobs\JobStatusTracker;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function boundaryImport(): array
{
    Storage::fake('production_imports');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id, 'role' => CompanyRole::Owner]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('products.csv', "name,slug\nOne,one\nTwo,two"), ImportMode::Create, chunkSize: 1);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);

    return [$actor, $company, $job];
}

test('large import denies viewer before any queue state or job registration', function () {
    [$actor, $company, $job] = boundaryImport();
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create(['user_id' => $viewer->id, 'company_id' => $company->id, 'role' => CompanyRole::Viewer]);
    config(['imports.queue_threshold_rows' => 1]);
    Queue::fake();
    test()->actingAs($viewer)->post(route('catalog.imports.apply', $job))->assertForbidden();
    expect($job->fresh()->status->value)->toBe('previewed');
    Queue::assertNothingPushed();
    test()->assertDatabaseCount('job_statuses', 0);
});

test('a chunk failure rolls back product writes and persists its failure report for retry', function () {
    [$actor, $company, $job] = boundaryImport();
    ImportRecord::saving(function ($record): void {
        if (in_array($record->status->value, ['applied', 'failed'], true)) {
            throw new RuntimeException('Injected chunk persistence failure');
        }
    });
    expect(fn () => app(ApplyImportJobAction::class)->execute($actor, $company, $job))
        ->toThrow(RuntimeException::class, 'Injected chunk persistence failure');
    expect($job->refresh()->status->value)->toBe('failed')
        ->and($job->failure_message)->toBe('Injected chunk persistence failure')
        ->and($job->processed_rows)->toBe(0)->and($job->created_rows)->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(0)
        ->and($job->records()->where('status', 'valid')->count())->toBe(2);
    Event::forget('eloquent.saving: '.ImportRecord::class);
    $result = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($result->status->value)->toBe('processing')->and($result->processed_rows)->toBe(1);
});

test('start action dispatches exactly once after checking the preview state', function () {
    [$actor, $company, $job] = boundaryImport();
    Queue::fake();
    config(['imports.queue_threshold_rows' => 1]);
    [$started, $status, $queued] = app(StartImportJobAction::class)->execute($actor, $company, $job);
    expect($queued)->toBeTrue()->and($started->status->value)->toBe('processing');
    Queue::assertPushed(ApplyImportJob::class, 1);
    try {
        app(StartImportJobAction::class)->execute($actor, $company, $job);
        test()->fail('A second start must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('job');
    }
    Queue::assertPushed(ApplyImportJob::class, 1);
});

test('a row rollback clears in memory product references before persisting its failure', function () {
    [$actor, $company, $job] = boundaryImport();
    $job->forceFill(['chunk_size' => 2])->save();
    ImportRecord::saved(function (ImportRecord $record): void {
        if ($record->row_number === 2 && $record->status->value === 'applied') {
            throw new RuntimeException('Failure after applied record write');
        }
    });
    try {
        $result = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    } finally {
        Event::forget('eloquent.saved: '.ImportRecord::class);
    }
    $failed = $job->records()->where('row_number', 2)->sole();
    expect($result->status->value)->toBe('completed')
        ->and($result->created_rows)->toBe(1)->and($result->skipped_rows)->toBe(1)
        ->and($result->processed_rows)->toBe(2)->and($failed->status->value)->toBe('failed')
        ->and($failed->target_product_id)->toBeNull()->and($failed->applied_at)->toBeNull()
        ->and(Product::query()->forCompany($company)->pluck('slug')->all())->toBe(['two']);
});

test('start action rejects viewer and terminal states without writes', function () {
    [$actor, $company, $job] = boundaryImport();
    Queue::fake();
    config(['imports.queue_threshold_rows' => 1]);
    CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $actor->id)->update(['role' => 'viewer']);
    try {
        app(StartImportJobAction::class)->execute($actor, $company, $job);
        test()->fail('Viewer must be rejected.');
    } catch (AuthorizationException $exception) {
        expect($job->fresh()->status->value)->toBe('previewed');
    }
    Queue::assertNothingPushed();
});

test('cancel import requires a company write permission', function () {
    [$actor, $company, $job] = boundaryImport();
    $job->forceFill(['status' => 'processing'])->save();
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create(['user_id' => $viewer->id, 'company_id' => $company->id, 'role' => CompanyRole::Viewer]);
    test()->actingAs($viewer)->post(route('catalog.imports.cancel', $job))->assertForbidden();
    expect($job->fresh()->cancel_requested_at)->toBeNull();
});

test('large import cannot restart a terminal or uploaded job', function (string $state) {
    [$actor, $company, $job] = boundaryImport();
    $job->forceFill(['status' => $state])->save();
    config(['imports.queue_threshold_rows' => 1]);
    Queue::fake();
    $response = test()->post(route('catalog.imports.apply', $job));
    $response->assertRedirect();
    $response->assertSessionHas('errors');
    expect($job->fresh()->status->value)->toBe($state);
    Queue::assertNothingPushed();
})->with(['completed', 'cancelled', 'uploaded', 'processing']);

test('import worker initializes and clears tenant context on a fresh worker', function () {
    [$actor, $company, $job] = boundaryImport();
    app(CurrentCompany::class)->clear();
    app(TokenCurrentCompany::class)->clear();
    (new ApplyImportJob($company->id, $job->id, $actor->id))->handle(app(ApplyImportJobAction::class), app(JobStatusTracker::class));
    expect($job->fresh()->status->value)->toBe('completed')
        ->and(Product::query()->forCompany($company)->count())->toBe(2)
        ->and(app(CurrentCompany::class)->get())->toBeNull()
        ->and(app(TokenCurrentCompany::class)->get())->toBeNull();
});

test('queued import does one bounded chunk and dispatches only its remaining work', function () {
    [$actor, $company, $job] = boundaryImport();
    config(['queue.default' => 'database']);
    Queue::fake();
    $worker = new ApplyImportJob($company->id, $job->id, $actor->id);
    $worker->handle(app(ApplyImportJobAction::class), app(JobStatusTracker::class));
    expect($job->fresh()->processed_rows)->toBe(1);
    Queue::assertPushed(ApplyImportJob::class, 1);
    $worker->handle(app(ApplyImportJobAction::class), app(JobStatusTracker::class));
    expect($job->fresh()->status->value)->toBe('completed')->and($job->fresh()->processed_rows)->toBe(2);
    Queue::assertPushed(ApplyImportJob::class, 1);
    $worker->handle(app(ApplyImportJobAction::class), app(JobStatusTracker::class));
    Queue::assertPushed(ApplyImportJob::class, 1);
    expect(Product::query()->forCompany($company)->count())->toBe(2);
});

test('import worker ignores a stale session tenant and restores the caller context', function () {
    [$actor, $company, $job] = boundaryImport();
    $otherCompany = Company::factory()->create();
    app(CurrentCompany::class)->set($otherCompany);
    (new ApplyImportJob($company->id, $job->id, $actor->id))->handle(app(ApplyImportJobAction::class), app(JobStatusTracker::class));
    expect($job->fresh()->status->value)->toBe('completed')
        ->and(Product::query()->forCompany($otherCompany)->count())->toBe(0)
        ->and(app(CurrentCompany::class)->get()->id)->toBe($otherCompany->id);
});

test('a late worker failure cannot overwrite a terminal import', function (string $terminal) {
    [$actor, $company, $job] = boundaryImport();
    $job->forceFill(['status' => $terminal])->save();
    (new ApplyImportJob($company->id, $job->id, $actor->id))->failed(new RuntimeException('Late failure'));
    expect($job->fresh()->status->value)->toBe($terminal);
})->with(['completed', 'cancelled']);

test('cancellation remains available when the original source file is gone', function () {
    [$actor, $company, $job] = boundaryImport();
    $job->forceFill(['status' => 'processing'])->save();
    Storage::disk(config('imports.disk'))->delete($job->source_path);
    app(StartImportJobAction::class)->cancel($actor, $company, $job);
    expect($job->fresh()->cancel_requested_at)->not->toBeNull();
});

test('completing an import creates one tenant webhook outbox event and retries create none', function () {
    [$actor, $company, $job] = boundaryImport();
    Queue::fake();
    foreach ([$company, Company::factory()->create()] as $endpointCompany) {
        WebhookEndpoint::create([
            'company_id' => $endpointCompany->id, 'url' => 'https://example.test/imports',
            'secret' => 'fixture-secret', 'events' => ['import.completed'], 'status' => 'active',
        ]);
    }
    $action = app(ApplyImportJobAction::class);
    $action->execute($actor, $company, $job);
    test()->assertDatabaseCount('webhook_deliveries', 0);
    $action->execute($actor, $company, $job);
    $action->execute($actor, $company, $job);
    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->company_id)->toBe($company->id)
        ->and($delivery->payload['data'])->toEqual(['import_job_uuid' => $job->uuid, 'processed_rows' => 2,
            'created_rows' => 2, 'updated_rows' => 0, 'skipped_rows' => 0]);
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});
