<?php

use App\Support\Catalog\GtinValidator;

test('valid GTIN formats and leading zeroes pass check digit validation', function (string $gtin) {
    $validator = new GtinValidator;

    expect($validator->isValid($gtin))->toBeTrue();
    $validator->assertValid($gtin);
})->with([
    'GTIN-8' => ['95012346'],
    'GTIN-12' => ['036000291452'],
    'GTIN-13' => ['4006381333931'],
    'GTIN-14' => ['10614141000415'],
    'leading zero GTIN-8' => ['00000000'],
]);

test('invalid GTIN input is rejected with a specific safe message', function (string $gtin, string $message) {
    $validator = new GtinValidator;

    expect($validator->isValid($gtin))->toBeFalse()
        ->and(fn () => $validator->assertValid($gtin))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'invalid check digit' => ['95012345', 'The GTIN check digit is invalid.'],
    'letters' => ['ABC12346', 'The GTIN must contain only digits.'],
    'spaces' => ['9501 2346', 'The GTIN must contain only digits.'],
    'hyphen' => ['9501-2346', 'The GTIN must contain only digits.'],
    'unsupported length' => ['1234567', 'The GTIN must contain 8, 12, 13, or 14 digits.'],
    'empty' => ['', 'The GTIN must contain only digits.'],
]);

test('check digit calculation follows the GS1 alternating weight algorithm', function () {
    $validator = new GtinValidator;

    expect($validator->calculateCheckDigit('9501234'))->toBe(6)
        ->and($validator->calculateCheckDigit('03600029145'))->toBe(2)
        ->and($validator->calculateCheckDigit('400638133393'))->toBe(1)
        ->and($validator->calculateCheckDigit('1061414100041'))->toBe(5)
        ->and($validator->calculateCheckDigit('0000000'))->toBe(0);
});
