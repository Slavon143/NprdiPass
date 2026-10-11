<?php

require_once __DIR__.'/Helpers.php';

use App\Models\Fortnox\FortnoxOAuthState;
use Illuminate\Support\Facades\Http;

test('connection page identifies trusted company environment and permitted account without starting OAuth', function () {
    Http::preventStrayRequests();
    app()->detectEnvironment(fn (): string => 'testing');
    config(['fortnox.nonproduction_tenant_id' => '1854146']);
    [$actor, $company] = fortnoxContext();
    $company->update(['name' => 'Trusted Integration Company']);

    $this->get(route('settings.integrations.fortnox.index', [
        'company_id' => 999999, 'tenant_id' => '1853390',
    ]))->assertOk()
        ->assertSee('data-testid="fortnox-account-context"', false)
        ->assertSee('Trusted Integration Company')
        ->assertSee('testing')
        ->assertSee('Permitted test account')
        ->assertSee('1854146');

    expect(FortnoxOAuthState::query()->count())->toBe(0);
    Http::assertNothingSent();
});
