<?php

use App\Actions\Companies\UpdateCompany;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

function validCompanySettingsPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Updated Company AB',
        'legal_name' => 'Updated Legal AB',
        'organization_number' => '559111-2222',
        'country_code' => 'no',
        'billing_email' => 'BILLING@EXAMPLE.TEST',
    ], $overrides);
}

test('company settings are editable for owner and admin and read only for editor and viewer', function (
    CompanyRole $role,
    bool $editable,
) {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Settings Company']);
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    $response = $this->actingAs($user)->get(route('settings.company.edit'));

    $response->assertOk()->assertSee('Settings Company');

    if ($editable) {
        $response->assertSee('name="name"', false)
            ->assertSee('Save company');
    } else {
        $response->assertSee('Read only')
            ->assertDontSee('name="name"', false)
            ->assertDontSee('Save company');
    }
})->with([
    'owner' => [CompanyRole::Owner, true],
    'admin' => [CompanyRole::Admin, true],
    'editor' => [CompanyRole::Editor, false],
    'viewer' => [CompanyRole::Viewer, false],
]);

test('owner and admin can update allowed company settings', function (CompanyRole $role) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    $this->actingAs($user)
        ->patch(route('settings.company.update'), validCompanySettingsPayload())
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHas('success', 'Company settings updated.');

    $company->refresh();

    expect($company->name)->toBe('Updated Company AB')
        ->and($company->country_code)->toBe('NO')
        ->and($company->billing_email)->toBe('billing@example.test');
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('editor and viewer cannot patch company settings', function (CompanyRole $role) {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Protected Company']);
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    $this->actingAs($user)
        ->patch(route('settings.company.update'), validCompanySettingsPayload())
        ->assertForbidden();

    expect($company->fresh()?->name)->toBe('Protected Company');
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('company update ignores protected and foreign fields', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'status' => CompanyStatus::Active,
        'settings' => ['locale' => 'sv'],
    ]);
    $foreignCompany = Company::factory()->create(['name' => 'Untouched Foreign Company']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    $originalUuid = $company->uuid;

    $this->actingAs($user)->patch(route('settings.company.update'), validCompanySettingsPayload([
        'company_id' => $foreignCompany->getKey(),
        'status' => CompanyStatus::Archived->value,
        'uuid' => '00000000-0000-0000-0000-000000000000',
        'settings' => ['locale' => 'xx'],
    ]))->assertRedirect(route('settings.company.edit'));

    $company->refresh();

    expect($company->name)->toBe('Updated Company AB')
        ->and($company->status)->toBe(CompanyStatus::Active)
        ->and($company->uuid)->toBe($originalUuid)
        ->and($company->settings)->toBe(['locale' => 'sv'])
        ->and($foreignCompany->fresh()?->name)->toBe('Untouched Foreign Company');
});

test('company update validation returns field errors and preserves old input', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->from(route('settings.company.edit'))
        ->patch(route('settings.company.update'), [
            'name' => '',
            'legal_name' => 'Remember this legal name',
            'organization_number' => null,
            'country_code' => 'SWE',
            'billing_email' => 'not-an-email',
        ])
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHasErrors(['name', 'country_code', 'billing_email'])
        ->assertSessionHasInput('legal_name', 'Remember this legal name');
});

test('update company action returns refreshed company and applies only its allowlist', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'status' => CompanyStatus::Active,
        'settings' => ['locale' => 'sv'],
    ]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $updatedCompany = app(UpdateCompany::class)->execute($user, $company, [
        ...validCompanySettingsPayload(['country_code' => 'DK']),
        'status' => CompanyStatus::Archived,
        'settings' => ['locale' => 'xx'],
    ]);

    expect($updatedCompany->is($company))->toBeTrue()
        ->and($updatedCompany->country_code)->toBe('DK')
        ->and($updatedCompany->status)->toBe(CompanyStatus::Active)
        ->and($updatedCompany->settings)->toBe(['locale' => 'sv']);
});
