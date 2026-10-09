<?php

test('the landing page presents the redesigned Digital Product Passport positioning', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('Digital Product Passports without the complexity')
        ->assertSee('14-day pilot')
        ->assertSee(route('register'))
        ->assertSee(route('login'))
        ->assertSee('images/marketing/industrial-storage-case-hd.png')
        ->assertSee('images/marketing/nordic-forest-panel.jpg')
        ->assertSee('Resources')
        ->assertSee('EN')
        ->assertSee('Import products from Fortnox / CSV')
        ->assertSee('Works with your existing tools')
        ->assertSee('Use the documented API for custom integrations')
        ->assertSee('Built for compliance. Designed for real business.')
        ->assertDontSee('ChatGPT Image')
        ->assertDontSee('h-32 w-44')
        ->assertDontSee('repeating-linear-gradient');
});

test('landing QR sizing does not stretch the phone preview logo', function () {
    $response = $this->get('/')->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $qrContainers = '//div[contains(concat(" ", normalize-space(@class), " "), " landing-qr ")]';

    expect($xpath->query($qrContainers.'/descendant::svg')->length)->toBe(2)
        ->and($xpath->query($qrContainers.'/descendant::svg[@viewbox="0 0 44 44"]')->length)->toBe(0);
});
