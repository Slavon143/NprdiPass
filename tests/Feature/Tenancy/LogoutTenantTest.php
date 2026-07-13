<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('logout clears the current company session value', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $company->id])
        ->post(route('logout'))
        ->assertRedirect('/')
        ->assertSessionMissing(config('tenancy.session_key'));

    $this->assertGuest();
});
