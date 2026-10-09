<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\ProductStatus;
use App\Models\Passports\PassportValidationRun;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ProductIndexReadinessProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->actor = User::factory()->create(['email_verified_at' => now()]);
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Readiness display boundaries');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    $this->product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Retained readiness']);
    $this->passport = app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $this->product);
});

test('limited product and passport display paths never execute the readiness evaluator', function (string $mode, bool $history) {
    if ($history) {
        $this->get(route('catalog.products.passport.readiness', $this->product->uuid))->assertOk();
    }
    $run = PassportValidationRun::query()->where('company_id', $this->company->id)->first();
    if ($mode === 'expired') {
        $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    } elseif ($mode === 'unmanaged') {
        $this->product->forceFill(['managed' => false])->save();
    } else {
        $this->product->forceFill(['status' => ProductStatus::Archived])->save();
    }
    $this->mock(PassportReadinessEvaluator::class)->shouldNotReceive('evaluate');
    $this->get(route('catalog.products.show', $this->product->uuid))->assertOk()
        ->assertSee($history ? 'Stored readiness evaluation' : 'No stored readiness evaluation');
    $this->get(route('catalog.products.passport.show', $this->product->uuid))->assertOk()
        ->assertSee($history ? 'Stored readiness evaluation' : 'No stored readiness evaluation')
        ->assertViewHas('readiness', fn ($result) => $history ? $result->score === $run->score : $result === null)
        ->assertViewHas('canManage', false)->assertViewHas('canPublish', false);
    $this->get(route('catalog.products.passport.edit', $this->product->uuid))
        ->assertRedirect(route('catalog.products.passport.show', $this->product->uuid));
    $this->get(route('catalog.products.passport.publish-confirm', $this->product->uuid))
        ->assertRedirect(route('catalog.products.passport.readiness', $this->product->uuid));
    $items = $this->company->products()->get();
    $summaries = app(ProductIndexReadinessProvider::class)->loadSummaries($this->company,
        new LengthAwarePaginator($items, $items->count(), 25));
    expect($summaries[$this->product->uuid]['score'])->toBe($run?->score)
        ->and($summaries[$this->product->uuid]['readiness_source'])->toBe($history ? 'stored' : 'unavailable')
        ->and(PassportValidationRun::query()->where('company_id', $this->company->id)->count())->toBe($history ? 1 : 0);
})->with([
    ['expired', false], ['expired', true], ['unmanaged', false], ['unmanaged', true], ['archived', false], ['archived', true],
]);
