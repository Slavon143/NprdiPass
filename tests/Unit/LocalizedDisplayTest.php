<?php

use App\Support\LocalizedDisplay;
use Carbon\CarbonImmutable;

test('localized display keeps decimal precision and separates source formats', function (string $locale, string $decimal, string $integer, string $date) {
    expect(LocalizedDisplay::decimal('0.0040', $locale))->toBe($decimal)
        ->and(LocalizedDisplay::decimal('1000', $locale))->toBe('1000')
        ->and(LocalizedDisplay::decimal('1200.0000', $locale))->toBe('1200')
        ->and(LocalizedDisplay::decimal('0.00400 kg', $locale))->toBe('0.00400 kg')
        ->and(LocalizedDisplay::integer(12345, $locale))->toBe($integer)
        ->and(LocalizedDisplay::date(CarbonImmutable::parse('2026-10-14'), $locale))->toBe($date);
})->with([
    ['en', '0.004', '12,345', '14 Oct 2026'],
    ['sv', '0,004', "12\u{00A0}345", '2026-10-14'],
    ['de', '0,004', '12.345', '14.10.2026'],
]);
