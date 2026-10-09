<?php

use App\Actions\Api\CreateCompanyApiToken;
use App\Actions\Api\RevokeCompanyApiToken;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Companies\CancelCompanyInvitation;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Companies\InviteCompanyMember;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UnpublishProductPassport;
use App\Enums\ApiTokenAbility;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyRole;
use App\Enums\Imports\ImportMode;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Jobs\Imports\ApplyImportJob;
use App\Models\CompanyMembership;
use App\Models\Passports\PassportValidationRun;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Jobs\JobStatusTracker;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
    $this->actor = User::factory()->create(['email_verified_at' => now()]);
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Commercial boundaries', '1234567890', 'SE');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
});

test('trial expiration blocks updates to existing products without deleting tenant data', function () {
    $product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Retained product']);
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    expect(fn () => app(UpdateProductAction::class)->execute($this->actor, $this->company, $product, ['name' => 'Unauthorized edit']))
        ->toThrow(ValidationException::class);
    expect($product->fresh()->name)->toBe('Retained product')->and($this->company->products()->count())->toBe(1);
});

test('API mutation uses the same expired trial boundary and stable response envelope', function () {
    $issued = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::CatalogWrite->value]);
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    Auth::forgetGuards();
    $response = $this->withToken($issued->plainTextToken)->postJson('/api/v1/catalog/products', ['name' => 'Blocked API product']);
    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_error')->assertHeader('X-Request-ID');
    expect($this->company->products()->count())->toBe(0);
});

test('an already prepared import cannot mutate products when its worker starts after trial expiration', function () {
    Storage::fake('production_imports');
    $job = app(CreateImportJobAction::class)->execute($this->actor, $this->company,
        UploadedFile::fake()->createWithContent('products.csv', "name,slug,brand,manufacturer\nQueued,queued,Nordic,Maker\n"), ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($this->actor, $this->company, $job,
        ['name' => 'name', 'slug' => 'slug', 'brand' => 'brand', 'manufacturer' => 'manufacturer']);
    $status = $job->status;
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    $worker = new ApplyImportJob($this->company->id, $job->id, $this->actor->id);
    expect(fn () => $worker->handle(app(ApplyImportJobAction::class), app(JobStatusTracker::class)))
        ->toThrow(ValidationException::class);
    expect($job->fresh()->processed_rows)->toBe(0)->and($job->fresh()->status)->toBe($status)
        ->and($this->company->products()->count())->toBe(0);
});

test('trial expiration during a chunk rolls back its admitted rows without marking them invalid', function () {
    Storage::fake('production_imports');
    $job = app(CreateImportJobAction::class)->execute($this->actor, $this->company,
        UploadedFile::fake()->createWithContent('products.csv', "name,slug,brand,manufacturer\nFirst,first,Nordic,Maker\nSecond,second,Nordic,Maker\n"), ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($this->actor, $this->company, $job,
        ['name' => 'name', 'slug' => 'slug', 'brand' => 'brand', 'manufacturer' => 'manufacturer']);
    $end = $this->company->subscription()->firstOrFail()->trial_ends_at;
    $advanced = false;
    DB::listen(function ($query) use ($end, &$advanced) {
        if (! $advanced && str_starts_with($query->sql, 'update `import_records`') && str_contains($query->sql, '`applied_at`')) {
            $advanced = true;
            $this->travelTo($end->addSecond());
        }
    });
    expect(fn () => app(ApplyImportJobAction::class)->execute($this->actor, $this->company, $job))
        ->toThrow(ValidationException::class);
    expect($advanced)->toBeTrue()->and($this->company->products()->count())->toBe(0)
        ->and($job->fresh()->processed_rows)->toBe(0)
        ->and($job->fresh()->records()->where('status', 'valid')->count())->toBe(2);
});

test('expired trial prevents new invitations but permits canceling a retained invitation', function () {
    $invitation = app(InviteCompanyMember::class)->execute($this->actor, $this->company, 'pending@example.test', CompanyRole::Editor)->invitation();
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    expect(fn () => app(InviteCompanyMember::class)->execute($this->actor, $this->company, 'another@example.test', CompanyRole::Editor))
        ->toThrow(ValidationException::class);
    app(CancelCompanyInvitation::class)->execute($this->actor, $invitation);
    expect($invitation->fresh()->cancelled_at)->not->toBeNull();
});

test('expired trial permits token revocation without permitting a replacement token', function () {
    $issued = app(CreateCompanyApiToken::class)->execute($this->actor, $this->company, 'Retained token', [ApiTokenAbility::CatalogRead], now()->addDays(30));
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    app(RevokeCompanyApiToken::class)->execute($this->actor, $this->company, $issued->accessToken);
    expect($issued->accessToken->fresh())->toBeNull();
    expect(fn () => app(CreateCompanyApiToken::class)->execute($this->actor, $this->company, 'Replacement', [ApiTokenAbility::CatalogRead], now()->addDays(30)))
        ->toThrow(ValidationException::class);
});

test('Starter cannot issue an API token even when its owner has the company permission', function () {
    $this->company->subscription()->firstOrFail()->update(['status' => BillingSubscriptionStatus::Active, 'plan_key' => 'starter',
        'entitlements' => config('billing.commercial_plans.starter.entitlements')]);
    expect(fn () => app(CreateCompanyApiToken::class)->execute($this->actor, $this->company, 'Unsupported API', [ApiTokenAbility::CatalogRead], now()->addDays(30)))
        ->toThrow(ValidationException::class);
});

test('expired trial preserves unpublication permissions and the published payload', function (CompanyRole $role) {
    $product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Retained publication']);
    $passport = app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $product);
    $published = ProductPassportVersion::factory()->published()->create([
        'company_id' => $this->company->id, 'passport_id' => $passport->id, 'published_by' => $this->actor->id,
    ]);
    $passport->forceFill(['status' => ProductPassportStatus::Published, 'current_published_version_id' => $published->id])->save();
    $checksum = $published->content_checksum;
    $payload = $published->payload;
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    CompanyMembership::query()->where('company_id', $this->company->id)->where('user_id', $this->actor->id)->update(['role' => $role]);
    $this->actor->refresh();
    if ($role === CompanyRole::Viewer) {
        expect(fn () => app(UnpublishProductPassport::class)->handle($this->actor, $this->company, $product, $passport))
            ->toThrow(AuthorizationException::class);
        expect($published->fresh()->status)->toBe(ProductPassportVersionStatus::Published)
            ->and($published->fresh()->payload)->toBe($payload);

        return;
    }
    app(UnpublishProductPassport::class)->handle($this->actor, $this->company, $product, $passport);
    expect($published->fresh()->status)->toBe(ProductPassportVersionStatus::Withdrawn)
        ->and($published->fresh()->content_checksum)->toBe($checksum)
        ->and($published->fresh()->payload)->toBe($payload);
})->with([CompanyRole::Owner, CompanyRole::Editor, CompanyRole::Viewer]);

test('expired trial displays stored readiness without executing a changed ruleset or persisting another run', function () {
    $product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Stored readiness']);
    app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $product);
    $url = route('catalog.products.passport.readiness', $product->uuid);
    $this->get($url)->assertOk();
    $run = PassportValidationRun::query()->where('company_id', $this->company->id)->firstOrFail();
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    $this->mock(PassportReadinessEvaluator::class)->shouldNotReceive('evaluate');
    $this->get($url)->assertOk()->assertSee('Stored readiness evaluation')->assertViewHas('readiness',
        fn ($result) => $result->ruleSetFingerprint === $run->rule_set_fingerprint && $result->score === $run->score);
    expect(PassportValidationRun::query()->where('company_id', $this->company->id)->count())->toBe(1);
});
