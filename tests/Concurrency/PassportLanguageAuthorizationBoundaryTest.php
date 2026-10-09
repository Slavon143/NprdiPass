<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdatePassportLanguagesAction;
use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

test('passport language changes reject revocation committed behind an older transaction snapshot', function (string $revocation) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->editor()->create(['user_id' => $actor, 'company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Languages', 'slug' => 'languages']);
    $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    $originalLanguages = $passport->enabled_languages;
    $originalDefault = $passport->default_language;
    $draft = $passport->currentDraftVersion;
    $originalPayload = $draft->payload;
    $originalRevision = $draft->draft_revision;
    $auditCount = AuditLog::query()->where('company_id', $company->id)->count();
    config()->set('database.connections.mysql_language_revoker', config('database.connections.mysql'));
    DB::purge('mysql_language_revoker');
    $revoker = DB::connection('mysql_language_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        Company::query()->findOrFail($company->id);
        User::query()->findOrFail($actor->id);
        expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::PassportsManage))->toBeTrue();
        match ($revocation) {
            'role' => $revoker->table('company_user')->where('id', $membership->id)->update(['role' => 'viewer', 'is_owner' => false]),
            'company' => $revoker->table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
            'user' => $revoker->table('users')->where('id', $actor->id)->update(['status' => 'suspended']),
        };
        expect(fn () => app(UpdatePassportLanguagesAction::class)->handle($actor, $company, $product, $passport, 'en', ['en', 'sv']))
            ->toThrow(AuthorizationException::class);
        expect($passport->fresh()->enabled_languages)->toBe($originalLanguages)
            ->and($passport->fresh()->default_language)->toBe($originalDefault)
            ->and($draft->fresh()->draft_revision)->toBe($originalRevision)
            ->and($draft->fresh()->payload)->toBe($originalPayload)
            ->and(AuditLog::query()->where('company_id', $company->id)->count())->toBe($auditCount);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_language_revoker');
    }
})->with(['role', 'company', 'user']);

test('passport language changes preserve a newer committed draft behind an older snapshot', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->editor()->create(['user_id' => $actor, 'company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Languages', 'slug' => 'languages']);
    $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    $draft = $passport->currentDraftVersion;
    $originalRevision = $draft->draft_revision;
    $newerPayload = $draft->payload;
    $newerPayload['translations']['sv']['identity']['public_name'] = 'Committed name';
    config()->set('database.connections.mysql_language_revoker', config('database.connections.mysql'));
    DB::purge('mysql_language_revoker');
    $revoker = DB::connection('mysql_language_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        $draft->fresh();
        $revoker->transaction(function () use ($revoker, $company, $passport, $draft, $newerPayload, $originalRevision): void {
            $revoker->table('companies')->where('id', $company->id)->lockForUpdate()->first();
            $revoker->table('product_passports')->where('id', $passport->id)->lockForUpdate()->first();
            $revoker->table('product_passport_versions')->where('id', $draft->id)->update([
                'payload' => json_encode($newerPayload, JSON_THROW_ON_ERROR),
                'draft_revision' => $originalRevision + 1,
            ]);
        });
        app(UpdatePassportLanguagesAction::class)->handle($actor, $company, $product, $passport, 'en', ['en', 'sv']);
        $current = $draft->fresh();
        expect($current->draft_revision)->toBe($originalRevision + 2)
            ->and($current->payload['translations']['sv']['identity']['public_name'] ?? null)->toBe('Committed name')
            ->and($current->payload['languages'])->toBe(['default' => 'en', 'enabled' => ['en', 'sv']]);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_language_revoker');
    }
});
