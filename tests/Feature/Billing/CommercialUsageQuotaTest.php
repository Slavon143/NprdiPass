<?php

use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Imports\CreateImportJobAction;
use App\Enums\ApiTokenAbility;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Imports\ImportMode;
use App\Models\Imports\ImportJob;
use App\Models\User;
use App\Services\Billing\CommercialUsageAdmission;
use App\Services\Billing\CommercialUsageWindow;
use App\Tenancy\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('production_imports');
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Usage quotas');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
});

test('accepted imports consume one unit per source job and duplicate requests cannot charge twice', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['imports.jobs.max'] = 1;
    $subscription->update(['entitlements' => $entitlements]);
    $file = fn () => UploadedFile::fake()->createWithContent('products.csv', "name,slug\nSource,source\n");
    $first = app(CreateImportJobAction::class)->execute($this->actor, $this->company, $file(), ImportMode::Create, 'accepted-once');
    $retry = app(CreateImportJobAction::class)->execute($this->actor, $this->company, $file(), ImportMode::Create, 'accepted-once');
    expect($retry->uuid)->toBe($first->uuid)
        ->and(DB::table('commercial_usage_buckets')->where('company_id', $this->company->id)->value('used'))->toBe(1)
        ->and(DB::table('commercial_usage_events')->where('company_id', $this->company->id)->count())->toBe(1);
    expect(fn () => app(CreateImportJobAction::class)->execute($this->actor, $this->company, $file(), ImportMode::Create, 'denied-second'))
        ->toThrow(ValidationException::class);
    expect(ImportJob::query()->forCompany($this->company)->count())->toBe(1)
        ->and(count(Storage::disk('production_imports')->allFiles()))->toBe(1);
});

test('usage and operation identity roll back together when the containing business transaction fails', function () {
    $operation = (string) Str::uuid();
    expect(fn () => DB::transaction(function () use ($operation) {
        app(CommercialUsageAdmission::class)->consume($this->company, 'imports', $operation);
        throw new RuntimeException('Definite business rollback');
    }))->toThrow(RuntimeException::class);
    expect(DB::table('commercial_usage_events')->count())->toBe(0)
        ->and(DB::table('commercial_usage_buckets')->count())->toBe(0);
    DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($this->company, 'imports', $operation));
    DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($this->company, 'imports', $operation));
    expect(DB::table('commercial_usage_events')->count())->toBe(1)
        ->and(DB::table('commercial_usage_buckets')->value('used'))->toBe(1);
});

test('catching a quota rejection inside an outer transaction cannot retain its operation event', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['imports.jobs.max'] = 1;
    $subscription->update(['entitlements' => $entitlements]);
    DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($this->company, 'imports', (string) Str::uuid()));
    $rejected = (string) Str::uuid();
    DB::transaction(function () use ($rejected) {
        try {
            app(CommercialUsageAdmission::class)->consume($this->company, 'imports', $rejected);
            $this->fail('Quota rejection was expected.');
        } catch (ValidationException) {
            // The surrounding business transaction intentionally remains open.
        }
        expect(DB::table('commercial_usage_events')->where('operation_id', $rejected)->exists())->toBeFalse();
    });
    expect(DB::table('commercial_usage_buckets')->value('used'))->toBe(1);
});

test('annual operational windows stay anchored at month end and use half open UTC boundaries', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $subscription->update(['status' => BillingSubscriptionStatus::Active,
        'current_period_start' => CarbonImmutable::parse('2027-01-31 12:00:00', 'UTC'),
        'current_period_end' => CarbonImmutable::parse('2028-01-31 12:00:00', 'UTC'),
        'metadata' => ['commercial_model' => 'v1', 'billing_interval' => 'year']]);
    $resolver = app(CommercialUsageWindow::class);
    $february = $resolver->resolve($subscription->fresh(), CarbonImmutable::parse('2027-02-28 12:00:00', 'UTC'));
    $march = $resolver->resolve($subscription->fresh(), CarbonImmutable::parse('2027-03-31 12:00:00', 'UTC'));
    expect($february['start']->toDateTimeString())->toBe('2027-02-28 12:00:00')
        ->and($february['end']->toDateTimeString())->toBe('2027-03-31 12:00:00')
        ->and($march['start']->toDateTimeString())->toBe('2027-03-31 12:00:00')
        ->and($march['end']->toDateTimeString())->toBe('2027-04-30 12:00:00');
    expect(fn () => $resolver->resolve($subscription->fresh(), CarbonImmutable::parse('2028-01-31 12:00:00', 'UTC')))
        ->toThrow(ValidationException::class);
});

test('API admission cannot reuse a client request ID to bypass the period quota', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['api.requests.max'] = 2;
    $subscription->update(['entitlements' => $entitlements]);
    $token = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::CompanyRead->value]);
    Auth::forgetGuards();
    for ($i = 0; $i < 2; $i++) {
        $this->withToken($token->plainTextToken)->withHeader('X-Request-ID', 'same-client-request-id')
            ->getJson('/api/v1/company')->assertOk()->assertHeader('X-Request-ID');
    }
    $this->withToken($token->plainTextToken)->withHeader('X-Request-ID', 'same-client-request-id')
        ->getJson('/api/v1/company')->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    expect(DB::table('commercial_usage_buckets')->where('metric', 'api')->value('used'))->toBe(2)
        ->and(DB::table('commercial_usage_events')->where('metric', 'api')->count())->toBe(2);
});

test('Starter disables an already issued token without changing token ownership', function () {
    $token = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::CompanyRead->value]);
    $this->company->subscription()->firstOrFail()->update(['status' => BillingSubscriptionStatus::Active,
        'plan_key' => 'starter', 'entitlements' => config('billing.commercial_plans.starter.entitlements')]);
    Auth::forgetGuards();
    $this->withToken($token->plainTextToken)->getJson('/api/v1/company')
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    expect($token->accessToken->fresh())->not->toBeNull()
        ->and(DB::table('commercial_usage_events')->count())->toBe(0);
});

test('invalid authentication and public health never consume the tenant API allowance', function () {
    Auth::forgetGuards();
    $this->withToken('invalid-fixture')->getJson('/api/v1/company')->assertUnauthorized();
    $this->getJson('/api/v1/health')->assertOk();
    expect(DB::table('commercial_usage_events')->count())->toBe(0);
});
