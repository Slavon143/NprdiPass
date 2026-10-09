<?php

test('public registration screen is available for self serve onboarding', function () {
    $this->get('/register')->assertOk()->assertSee('Organization name');
});

test('registration requires organization onboarding details', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['company_name', 'country_code']);

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});

test('registration creates the initial organization and trial subscription', function () {
    $this->post('/register', [
        'name' => 'Test Owner',
        'company_name' => 'Test Organization',
        'organization_number' => '556677-8899',
        'country_code' => 'SE',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('verification.notice'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('companies', [
        'name' => 'Test Organization',
        'organization_number' => '556677-8899',
        'country_code' => 'SE',
    ]);
});
