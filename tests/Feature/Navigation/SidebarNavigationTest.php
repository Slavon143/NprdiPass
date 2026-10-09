<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

test('sidebar keeps accessible destinations and an independent mobile drawer', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'company_id' => $company->getKey(),
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $response = $this->get(route('dashboard'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $desktop = '//aside[@id="desktop-navigation"]';
    $links = $xpath->query($desktop.'//nav//a');

    expect($links->length)->toBeGreaterThan(3);
    foreach ($links as $link) {
        expect($link->getAttribute('title'))->not->toBeEmpty()
            ->and(trim($link->textContent))->not->toBeEmpty();
        expect($xpath->query('svg', $link)->length)->toBe(1);
    }
    expect($xpath->query($desktop.'//button[@aria-controls="desktop-navigation"]')->length)->toBe(1)
        ->and($xpath->query('//div[@id="mobile-navigation"]//nav//a')->length)->toBe($links->length);

    // Plan details start closed in both navigations so the menu retains room.
    $planCards = $xpath->query('//details[@data-testid="sidebar-plan"]');
    expect($planCards->length)->toBe(2);
    foreach ($planCards as $card) {
        expect($card->hasAttribute('open'))->toBeFalse()
            ->and($xpath->query('summary', $card)->length)->toBe(1)
            ->and($xpath->query('summary//a', $card)->length)->toBe(0)
            ->and($xpath->query('div[@data-testid="sidebar-plan-details"]//a', $card)->length)->toBe(1);
    }
});

test('mobile navigation retains tenant identity and company switching for long names', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => str_repeat('User', 40)]);
    $current = Company::factory()->create(['name' => implode(' ', array_fill(0, 12, 'Current company'))]);
    $other = Company::factory()->create(['name' => implode(' ', array_fill(0, 12, 'Other company'))]);
    foreach ([$current, $other] as $company) {
        CompanyMembership::factory()->owner()->create([
            'user_id' => $user->getKey(),
            'company_id' => $company->getKey(),
        ]);
    }
    $this->actingAs($user);
    app(CurrentCompany::class)->set($current);

    $response = $this->get(route('dashboard'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $drawer = '//div[@id="mobile-navigation"]';
    $identity = $xpath->query($drawer.'//*[@data-testid="mobile-current-company"]');
    expect($identity->length)->toBe(1)
        ->and(trim($identity->item(0)->textContent))->toBe($current->name);
    $switchForms = $xpath->query($drawer.'//form[@action="'.route('companies.switch', $other).'"]');
    expect($switchForms->length)->toBe(1)
        ->and(strtolower($switchForms->item(0)->getAttribute('method')))->toBe('post')
        ->and($xpath->query('input[@name="_token"]', $switchForms->item(0))->length)->toBe(1)
        ->and(trim($xpath->query('button/span', $switchForms->item(0))->item(0)->textContent))->toBe($other->name);
});
