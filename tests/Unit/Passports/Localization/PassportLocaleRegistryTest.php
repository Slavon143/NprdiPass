<?php

use App\Services\Passports\Localization\PassportLocaleRegistry;

test('en locale is supported', function (): void {
    $registry = $this->app->make(PassportLocaleRegistry::class);

    expect($registry->supports('en'))->toBeTrue();
    expect($registry->get('en'))->not()->toBeNull();
    expect($registry->get('en')->label)->toBe('English');
    expect($registry->get('en')->nativeLabel)->toBe('English');
    expect($registry->get('en')->direction)->toBe('ltr');
    expect($registry->get('en')->htmlLang)->toBe('en');
});

test('sv locale is supported', function (): void {
    $registry = $this->app->make(PassportLocaleRegistry::class);

    expect($registry->supports('sv'))->toBeTrue();
    expect($registry->get('sv'))->not()->toBeNull();
    expect($registry->get('sv')->label)->toBe('Swedish');
    expect($registry->get('sv')->nativeLabel)->toBe('Svenska');
    expect($registry->get('sv')->direction)->toBe('ltr');
    expect($registry->get('sv')->htmlLang)->toBe('sv');
});

test('unsupported locale is rejected', function (): void {
    $registry = $this->app->make(PassportLocaleRegistry::class);

    expect($registry->supports('de'))->toBeFalse();
    expect($registry->supports('fr'))->toBeFalse();
    expect($registry->supports('xx'))->toBeFalse();
});

test('codes returns all supported codes', function (): void {
    $registry = $this->app->make(PassportLocaleRegistry::class);

    $codes = $registry->codes();
    expect($codes)->toContain('en');
    expect($codes)->toContain('sv');
});

test('default code comes from config', function (): void {
    config()->set('passports.default_language', 'en');

    $registry = $this->app->make(PassportLocaleRegistry::class);
    expect($registry->defaultCode())->toBe('en');

    config()->set('passports.default_language', 'sv');
    expect((new PassportLocaleRegistry)->defaultCode())->toBe('sv');
});
