<?php

use App\Actions\Companies\CreateCompanyForOwner;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Services\Billing\CommercialUsageOverview;
use App\Services\Billing\CommercialUsageWindow;
use App\Services\Storage\TenantStorageLedger;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans'), 'billing.default_plan' => 'growth']);
    Http::preventStrayRequests();
    Queue::fake();
    foreach (['catalog_media', 'product_documents', 'production_imports', 'passport_assets'] as $disk) {
        Storage::fake($disk);
    }
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Usage overview');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
});

test('usage overview counts pending seats and warns at ninety percent of current quotas', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $subscription->update(['entitlements' => [...$subscription->entitlements, 'storage.bytes.max' => 200]]);
    $window = app(CommercialUsageWindow::class)->resolve($subscription, now()->toImmutable()->utc());
    foreach (['imports' => 9, 'api' => 900] as $metric => $used) {
        DB::table('commercial_usage_buckets')->insert(['company_id' => $this->company->id, 'metric' => $metric,
            'window_start' => $window['start'], 'window_end' => $window['end'], 'used' => $used]);
    }
    CompanyInvitation::factory()->create(['company_id' => $this->company->id, 'role' => CompanyRole::Editor,
        'accepted_at' => null, 'cancelled_at' => null, 'expires_at' => now()->addDay()]);
    $key = $this->company->uuid.'/products/retained.bin';
    $ledger = app(TenantStorageLedger::class);
    $claim = $ledger->reserve($this->company, 'catalog_media', $key, 180, (string) Str::uuid());
    Storage::disk('catalog_media')->put($key, str_repeat('x', 180));
    $ledger->markWritten($this->company, $claim);
    $meters = collect(app(CommercialUsageOverview::class)->forCompany($this->company, $subscription)['meters'])->keyBy('key');
    expect($meters['seats']['used'])->toBe(2)->and($meters['storage']['used'])->toBe(180)
        ->and($meters['imports']['used'])->toBe(9)->and($meters['api']['used'])->toBe(900);
    expect($meters->every(fn ($meter) => $meter['near_limit']))->toBeTrue();
    $this->get(route('settings.billing.index'))->assertOk()->assertSee('Usage and allowances')
        ->assertSee('Storage allowance is almost full.')->assertSee('Import allowance is almost full.')
        ->assertSee('180 B')->assertSee('200 B');
});

test('uninitialized physical history is shown as unknown without creating a balance on a read', function () {
    Storage::disk('catalog_media')->put($this->company->uuid.'/retained.bin', 'retained bytes');
    $before = DB::table('commercial_storage_balances')->count();
    $this->get(route('settings.billing.index'))->assertOk()->assertSee('Not available yet');
    expect(DB::table('commercial_storage_balances')->count())->toBe($before);
});

test('usage overview excludes another tenant buckets and storage balance', function () {
    $foreign = Company::factory()->create();
    $subscription = $this->company->subscription()->firstOrFail();
    $window = app(CommercialUsageWindow::class)->resolve($subscription, now()->toImmutable()->utc());
    DB::table('commercial_usage_buckets')->insert(['company_id' => $foreign->id, 'metric' => 'imports',
        'window_start' => $window['start'], 'window_end' => $window['end'], 'used' => 777]);
    DB::table('commercial_storage_balances')->insert(['company_uuid' => $foreign->uuid, 'used_bytes' => 888, 'initialized_at' => now()]);
    $meters = collect(app(CommercialUsageOverview::class)->forCompany($this->company, $subscription)['meters'])->keyBy('key');
    expect($meters['imports']['used'])->toBe(0)->and($meters['storage']['used'])->toBeNull();
});

test('expired quota windows stay unknown and never masquerade as a fresh zero period', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $subscription->update(['trial_ends_at' => now()->subSecond()]);
    $overview = app(CommercialUsageOverview::class)->forCompany($this->company, $subscription);
    $meters = collect($overview['meters'])->keyBy('key');
    expect($overview['window_end'])->toBeNull()->and($meters['imports']['used'])->toBeNull()->and($meters['api']['used'])->toBeNull();
    $this->get(route('settings.billing.index'))->assertOk()->assertSee('Paused')->assertDontSee('quota period ends on');
});
