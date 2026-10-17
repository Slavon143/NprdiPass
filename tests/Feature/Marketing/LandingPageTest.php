<?php

test('the landing page presents the redesigned Digital Product Passport positioning', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('Digital Product Passports without the complexity')
        ->assertSee('Every product has a story.')
        ->assertSee('Explore the demo')
        ->assertSee('From product information to a shareable passport.')
        ->assertSee(route('demo', ['lang' => 'en', 'view' => 'passport']))
        ->assertSee(route('register'))
        ->assertSee(route('login'))
        ->assertSee('EN')
        ->assertSee('Explore sample import')
        ->assertSee('Keep working with the tools you know.')
        ->assertSee('Readiness evaluates selected requirements. It is not a legal certification.')
        ->assertDontSee('ChatGPT Image')
        ->assertDontSee('h-32 w-44')
        ->assertDontSee('repeating-linear-gradient');
});

test('new visitors can reach registration from the header and mobile menu', function (string $locale, string $label) {
    $response = $this->get('/?lang='.$locale)->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);

    foreach (['//header', '//nav[@data-public-menu]'] as $location) {
        $links = $xpath->query($location.'//a[@href="'.route('register', $locale === 'en' ? [] : ['lang' => $locale]).'"]');
        expect($links->length)->toBe(1)
            ->and(trim($links->item(0)->textContent))->toBe($label);
    }
})->with([
    ['en', 'Sign up'],
    ['sv', 'Registrera dig'],
]);

test('landing retains the prototype interface card and floating QR instead of a product photo', function () {
    $response = $this->get('/')->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $qrContainers = '//*[contains(concat(" ", normalize-space(@class), " "), " landing-qr ")]';

    expect($xpath->query($qrContainers.'/descendant::svg')->length)->toBe(1)
        ->and($xpath->query('//h1/br')->length)->toBe(2)
        ->and($xpath->query('//div[contains(@class,"hero-product-card")]//img')->length)->toBe(0)
        ->and($xpath->query('//a[contains(@class,"qr-floating")]')->length)->toBe(1);
});
