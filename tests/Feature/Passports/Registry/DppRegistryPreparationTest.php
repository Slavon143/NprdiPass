<?php

use App\Actions\Passports\PrepareDppRegistryRegistration;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\DppRegistryPreparation;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
    $this->actor = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->actor->id, 'role' => CompanyRole::Owner]);
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    View::share('currentCompany', $this->company);
    View::share('availableCompanies', new Collection([$this->company]));
    View::share('currentMembership', $this->actor->memberships()->where('company_id', $this->company->id)->first());
    $slug = 'registry-'.str()->uuid();
    $this->product = Product::query()->forceCreate(['company_id' => $this->company->id, 'name' => 'Synthetic registry fixture',
        'slug' => $slug, 'slug_normalized' => $slug, 'status' => ProductStatus::Active, 'managed' => true, 'created_by' => $this->actor->id]);
    $this->passport = ProductPassport::query()->forceCreate(['company_id' => $this->company->id, 'product_id' => $this->product->id,
        'status' => ProductPassportStatus::Published, 'default_language' => 'en', 'enabled_languages' => ['en'], 'created_by' => $this->actor->id]);
    $this->version = ProductPassportVersion::factory()->published()->create(['company_id' => $this->company->id,
        'passport_id' => $this->passport->id, 'published_by' => $this->actor->id,
        'payload' => ['languages' => ['default' => 'en'], 'regulatory_context' => ['product_type' => 'ev_battery']]]);
    $this->passport->forceFill(['current_published_version_id' => $this->version->id])->save();
    config(['passports.public_base_url' => 'https://example.com']);
});

test('registry preparation is idempotent, audited and cannot imply an EU registration', function () {
    $action = app(PrepareDppRegistryRegistration::class);
    $record = $action->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'acceptance');
    $again = $action->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'acceptance');
    expect($record->id)->toBe($again->id)->and($record->status)->toBe('blocked')
        ->and($record->registration_uri)->toBeNull()->and($record->snapshot_checksum)->toBe($this->version->content_checksum)
        ->and($record->passport_uri)->toBe('https://example.com/p/'.$this->passport->public_id)
        ->and($record->blockers)->toContain('official_registry_schema_unconfirmed', 'organisation_verification_unconfirmed');
    expect(AuditLog::query()->where('event', AuditEvent::PassportRegistryPrepared->value)->count())->toBe(1);
    $production = $action->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'production');
    expect($production->id)->not->toBe($record->id)->and($production->registration_uri)->toBeNull();
});

test('registry preparation rejects foreign tenant products and stale publication selection', function () {
    $foreign = ProductPassport::factory()->create()->product;
    expect(fn () => app(PrepareDppRegistryRegistration::class)->handle($this->actor, $this->company, $foreign, $this->version->uuid, 'acceptance'))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(PrepareDppRegistryRegistration::class)->handle($this->actor, $this->company, $this->product, (string) str()->uuid(), 'acceptance'))->toThrow(ConflictHttpException::class);
    expect(DppRegistryPreparation::query()->count())->toBe(0);
});

test('registry preparation rejects viewers and unmanaged products', function () {
    $viewer = User::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $this->company->id, 'user_id' => $viewer->id, 'role' => CompanyRole::Viewer]);
    expect(fn () => app(PrepareDppRegistryRegistration::class)->handle($viewer, $this->company, $this->product, $this->version->uuid, 'acceptance'))->toThrow(AuthorizationException::class);
    $this->product->forceFill(['managed' => false])->save();
    expect(fn () => app(PrepareDppRegistryRegistration::class)->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'acceptance'))->toThrow(ConflictHttpException::class);
});

test('audit failure rolls back registry preparation', function () {
    $this->mock(AuditLogger::class)->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(PrepareDppRegistryRegistration::class)->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'acceptance'))->toThrow(RuntimeException::class);
    expect(DppRegistryPreparation::query()->count())->toBe(0);
});

test('registry preparations remain frozen across republishing and SQL tampering', function () {
    $action = app(PrepareDppRegistryRegistration::class);
    $record = $action->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'acceptance');
    expect(fn () => $record->forceFill(['status' => 'success'])->save())->toThrow(LogicException::class);
    expect(fn () => DB::table('dpp_registry_preparations')->where('id', $record->id)->update(['status' => 'success']))->toThrow(QueryException::class);
    expect(fn () => DB::table('dpp_registry_preparations')->where('id', $record->id)->delete())->toThrow(QueryException::class);
    $this->version->forceFill(['status' => ProductPassportVersionStatus::Superseded, 'superseded_at' => now()])->save();
    $next = ProductPassportVersion::factory()->published()->create(['company_id' => $this->company->id, 'passport_id' => $this->passport->id, 'version_number' => 2]);
    $this->passport->forceFill(['current_published_version_id' => $next->id])->save();
    $nextRecord = $action->handle($this->actor, $this->company, $this->product, $next->uuid, 'acceptance');
    expect($nextRecord->id)->not->toBe($record->id)->and($record->fresh()->snapshot_checksum)->toBe($this->version->content_checksum);
});

test('registry ledger database foreign key prevents linking another tenant version', function () {
    $foreign = Company::factory()->create();
    expect(fn () => DB::table('dpp_registry_preparations')->insert(['uuid' => (string) str()->uuid(), 'company_id' => $foreign->id,
        'passport_id' => $this->passport->id, 'published_version_id' => $this->version->id, 'environment' => 'acceptance',
        'status' => 'blocked', 'snapshot_checksum' => $this->version->content_checksum, 'blockers' => '[]', 'created_at' => now()]))->toThrow(QueryException::class);
});

test('unsafe configured passport URLs are neither stored nor audited', function () {
    config(['passports.public_base_url' => 'https://secret@example.com']);
    $record = app(PrepareDppRegistryRegistration::class)->handle($this->actor, $this->company, $this->product, $this->version->uuid, 'acceptance');
    expect($record->passport_uri)->toBeNull()->and($record->blockers)->toContain('public_https_uri_required');
    expect(AuditLog::query()->where('event', AuditEvent::PassportRegistryPrepared->value)->first()->properties->toJson())->not->toContain('secret@example.com');
});

test('native registry page shows all battery positions and preparation remains local', function () {
    $this->get(route('catalog.products.passport.registry', $this->product->uuid))->assertOk()
        ->assertSee('battery-guidance-catalogue')->assertSee('Recorded state of charge');
    $this->post(route('catalog.products.passport.registry.prepare', $this->product->uuid), [
        'published_version_uuid' => $this->version->uuid, 'environment' => 'acceptance', 'company_id' => 99999,
    ])->assertRedirect(route('catalog.products.passport.registry', $this->product->uuid));
    expect(DppRegistryPreparation::query()->sole()->company_id)->toBe($this->company->id);
    $this->get(route('catalog.products.passport.registry', $this->product->uuid))->assertOk()->assertSee('Not issued');
    $foreign = ProductPassport::factory()->create()->product;
    $this->get(route('catalog.products.passport.registry', $foreign->uuid))->assertNotFound();
});
