<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200)
        ->assertSee('New to NordiPass?')
        ->assertSee('Create an account')
        ->assertSee('href="'.route('register').'"', false);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('user menu logout submits a post form without inline JavaScript', function () {
    config(['security.csp_enabled' => true]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[@action="'.route('logout').'"]');
    expect($forms)->not->toBeFalse()
        ->and($forms->length)->toBeGreaterThan(0);
    foreach ($forms as $form) {
        expect($form->getAttribute('method'))->toBe('POST')
            ->and($xpath->query('.//button[@type="submit"]', $form)->length)->toBe(1)
            ->and($xpath->query('.//*[@onclick]', $form)->length)->toBe(0)
            ->and($xpath->query('.//a', $form)->length)->toBe(0);
    }
});
