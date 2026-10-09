<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Authorization\ApiTokenMutationAuthorizer;
use App\Enums\ApiTokenAbility;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class ApiMutationRaceHook
{
    public static ?Closure $callback = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (self::$callback !== null) {
            (self::$callback)();
        }

        return $next($request);
    }
}

afterEach(function () {
    ApiMutationRaceHook::$callback = null;
});

function apiMutationContext(): array
{
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create(['user_id' => $user, 'company_id' => $company]);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::WebhooksManage->value, ApiTokenAbility::CatalogWrite->value]);

    return [$user, $company, $membership, $token];
}

function apiMutationPayload(): array
{
    return ['url' => 'https://example.test/boundary', 'secret' => 'fixture-endpoint-secret', 'events' => [WebhookEventType::PassportPublished->value]];
}

test('webhook mutation rejects credential state changed after middleware', function (string $change, int $status, string $code) {
    [$user, $company, $membership, $token] = apiMutationContext();
    $foreign = Company::factory()->create();
    Route::getRoutes()->getByName('api.v1.webhooks.endpoints.store')->middleware(ApiMutationRaceHook::class);
    ApiMutationRaceHook::$callback = function () use ($change, $token, $company, $user, $membership, $foreign): void {
        $tokens = DB::table('personal_access_tokens')->where('id', $token->accessToken->id);
        match ($change) {
            'revoke' => $tokens->delete(),
            'expire' => $tokens->update(['expires_at' => now()->subMinute()]),
            'ability' => $tokens->update(['abilities' => json_encode([ApiTokenAbility::CompanyRead->value])]),
            'tenant' => $tokens->update(['company_id' => $foreign->id]),
            'hash' => $tokens->update(['token' => hash('sha256', 'replacement-fixture-credential')]),
            'membership' => DB::table('company_user')->where('id', $membership->id)->delete(),
            'user' => DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']),
            'company' => DB::table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
        };
    };
    $this->withToken($token->plainTextToken)->postJson('/api/v1/webhooks/endpoints', apiMutationPayload())
        ->assertStatus($status)->assertJsonPath('error.code', $code)->assertHeader('X-Request-ID')
        ->assertJsonMissingPath('data.secret');
    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['revoke', 401, 'token_invalid'],
    ['expire', 401, 'token_expired'],
    ['ability', 403, 'token_ability_missing'],
    ['tenant', 401, 'token_invalid'],
    ['hash', 401, 'token_invalid'],
    ['membership', 403, 'forbidden'],
    ['user', 401, 'token_invalid'],
    ['company', 423, 'company_inactive'],
]);

test('revoked token cannot update or disable a webhook after middleware', function (string $operation) {
    [$user, $company, $membership, $token] = apiMutationContext();
    $endpoint = WebhookEndpoint::query()->create(['company_id' => $company->id, ...apiMutationPayload(), 'status' => WebhookEndpointStatus::Active]);
    $original = $endpoint->refresh()->getAttributes();
    Route::getRoutes()->getByName('api.v1.webhooks.endpoints.'.$operation)->middleware(ApiMutationRaceHook::class);
    ApiMutationRaceHook::$callback = fn () => DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->delete();
    $path = '/api/v1/webhooks/endpoints/'.$endpoint->uuid;
    $this->withToken($token->plainTextToken);
    $response = $operation === 'update' ? $this->patchJson($path, ['status' => 'disabled']) : $this->postJson($path.'/disable');
    $response->assertUnauthorized()->assertJsonPath('error.code', 'token_invalid')->assertHeader('X-Request-ID');
    expect($endpoint->fresh()->getAttributes())->toBe($original);
})->with(['update', 'disable']);

test('catalog transaction rejects a token revoked after middleware', function () {
    [$user, $company, $membership, $token] = apiMutationContext();
    Route::getRoutes()->getByName('api.v1.catalog.products.store')->middleware(ApiMutationRaceHook::class);
    ApiMutationRaceHook::$callback = fn () => DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->delete();
    $this->withToken($token->plainTextToken)->postJson('/api/v1/catalog/products', ['name' => 'Rejected API product'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'token_invalid');
    expect(Product::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

test('webhook token ability survives a role downgrade with membership intact', function () {
    [$user, $company, $membership, $token] = apiMutationContext();
    Route::getRoutes()->getByName('api.v1.webhooks.endpoints.store')->middleware(ApiMutationRaceHook::class);
    ApiMutationRaceHook::$callback = fn () => DB::table('company_user')->where('id', $membership->id)->update(['role' => 'viewer', 'is_owner' => false]);
    $this->withToken($token->plainTextToken)->postJson('/api/v1/webhooks/endpoints', apiMutationPayload())
        ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.secret');
    expect(WebhookEndpoint::query()->forCompany($company)->count())->toBe(1);
});

test('import template mutation rejects a token revoked after middleware', function (bool $existing) {
    config()->set('features.production_imports', true);
    [$user, $company, $membership, $token] = apiMutationContext();
    $token->accessToken->forceFill(['abilities' => [ApiTokenAbility::ImportsWrite->value]])->save();
    $template = $existing ? ImportMappingTemplate::query()->create([
        'company_id' => $company->id, 'name' => 'Supplier', 'name_normalized' => 'supplier',
        'entity_type' => 'product', 'mapping' => ['name' => 'Original'],
    ]) : null;
    $original = $template?->refresh()->getAttributes();
    Route::getRoutes()->getByName('api.v1.catalog.import-templates.store')->middleware(ApiMutationRaceHook::class);
    ApiMutationRaceHook::$callback = fn () => DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->delete();
    $this->withToken($token->plainTextToken)->postJson('/api/v1/catalog/import-templates', [
        'name' => 'Supplier', 'mapping' => ['name' => 'Replacement'],
    ])->assertUnauthorized()->assertJsonPath('error.code', 'token_invalid');
    expect(ImportMappingTemplate::query()->forCompany($company)->count())->toBe($existing ? 1 : 0);
    if ($existing) {
        expect($template->fresh()->getAttributes())->toBe($original);
    }
})->with([false, true]);

test('failed API mutation does not retain credentials in a later web action', function () {
    [$user, $company, $membership, $token] = apiMutationContext();
    Route::getRoutes()->getByName('api.v1.webhooks.endpoints.store')->middleware(ApiMutationRaceHook::class);
    ApiMutationRaceHook::$callback = fn () => DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->delete();
    $this->withToken($token->plainTextToken)->postJson('/api/v1/webhooks/endpoints', apiMutationPayload())->assertUnauthorized();
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($user, $company, ['name' => 'Authorized web product']);
    expect($product->exists)->toBeTrue()->and(WebhookEndpoint::query()->count())->toBe(0);
});

test('all protected API write routes declare an ability boundary', function () {
    $count = 0;
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') || array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }
        $abilities = array_filter($route->gatherMiddleware(), fn ($middleware) => str_starts_with($middleware, 'api.ability:'));
        expect($abilities)->not->toBeEmpty();
        $count++;
    }
    expect($count)->toBeGreaterThan(0);
});

test('scalar identity worker authorization does not inherit an HTTP credential', function () {
    [$user, $company, $membership, $token] = apiMutationContext();
    app(CurrentCompany::class)->set($company);
    app(ApiTokenMutationAuthorizer::class)->withToken($token->accessToken, ApiTokenAbility::WebhooksManage->value,
        function () use ($user, $company, $token): void {
            // A worker loads actor state from its scalar identifier, even when
            // the sync queue driver runs it before the HTTP middleware unwinds.
            DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->delete();
            $workerActor = User::query()->findOrFail($user->id);
            expect($workerActor->currentAccessToken())->toBeNull();
            $product = app(CreateProductAction::class)->execute($workerActor, $company, ['name' => 'Previously accepted work']);
            expect($product->exists)->toBeTrue();
        });
});
