<?php

use App\Services\Dpp\DppRegulatoryContext;
use App\Services\Dpp\DppRegulatoryScope;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

test('battery scope distinguishes types and the strict industrial capacity threshold', function (string $type, ?float $capacity, string $expected) {
    $facts = ['market' => 'EU', 'product_type' => $type, 'battery_capacity_kwh' => $capacity,
        'passport_level' => 'item', 'item_identifier' => 'TEST-ONLY-BATTERY', 'assessment_source' => 'Synthetic test only', 'market_event_date' => '2027-02-18'];
    $scope = app(DppRegulatoryScope::class)->assess($facts, CarbonImmutable::parse('2027-02-18'));
    expect($scope['status'])->toBe($expected)->and($scope['registry_required'])->toBe($expected === 'mandatory');
})->with([
    ['ev_battery', 0.2, 'mandatory'], ['lmt_battery', 0.4, 'mandatory'],
    ['industrial_battery', 1.9, 'not_applicable'], ['industrial_battery', 2.0, 'not_applicable'],
    ['industrial_battery', 2.001, 'mandatory'], ['portable_battery', 3.0, 'not_applicable'],
]);

test('battery obligation is future before February 18 and requires instance identity', function () {
    $facts = ['market' => 'EU', 'product_type' => 'lmt_battery', 'passport_level' => 'model', 'assessment_source' => 'Synthetic test only'];
    $scope = app(DppRegulatoryScope::class)->assess($facts, CarbonImmutable::parse('2027-02-17'));
    expect($scope['status'])->toBe('upcoming')->and($scope['item_identity_complete'])->toBeFalse()
        ->and($scope['registry_required'])->toBeFalse();
});

test('electrical product classification does not invent a sector act', function () {
    $scope = app(DppRegulatoryScope::class)->assess(['market' => 'EU', 'product_type' => 'electrical',
        'passport_level' => 'model', 'assessment_source' => 'Manufacturer catalogue'], CarbonImmutable::parse('2026-10-09'));
    expect($scope['status'])->toBe('sector_assessment_required')->and($scope['registry_required'])->toBeNull();
});

test('earlier battery placement is distinguished from a later remanufacturing event', function () {
    $facts = ['market' => 'EU', 'product_type' => 'ev_battery', 'passport_level' => 'item',
        'item_identifier' => 'TEST-ONLY', 'assessment_source' => 'Synthetic lifecycle evidence',
        'market_event_date' => '2027-02-17', 'lifecycle_event' => 'initial_placement'];
    $scope = app(DppRegulatoryScope::class)->assess($facts, CarbonImmutable::parse('2027-03-01'));
    expect($scope['status'])->toBe('not_applicable')->and($scope['registry_required'])->toBeFalse();
    $facts['market_event_date'] = '2027-02-20';
    $facts['lifecycle_event'] = 'remanufacturing';
    $scope = app(DppRegulatoryScope::class)->assess($facts, CarbonImmutable::parse('2027-03-01'));
    expect($scope['status'])->toBe('mandatory')->and($scope['registry_required'])->toBeTrue();
});

test('missing event dates cannot create a retrospective exemption', function (?string $eventDate) {
    $scope = app(DppRegulatoryScope::class)->assess(['market' => 'EU', 'product_type' => 'lmt_battery',
        'passport_level' => 'item', 'assessment_source' => 'Synthetic test', 'market_event_date' => $eventDate],
        CarbonImmutable::parse('2027-02-18'));
    expect($scope['status'])->toBe('assessment_incomplete')->and($scope['registry_required'])->toBeNull();
})->with([null, '']);

test('invalid or forged context is rejected rather than falling back to unclassified', function (array $facts) {
    $scope = app(DppRegulatoryScope::class)->assess($facts, CarbonImmutable::parse('2026-10-09'));
    expect($scope['status'])->toBe('invalid');
    expect(fn () => app(DppRegulatoryContext::class)->validate($facts))->toThrow(ValidationException::class);
})->with([
    [['market' => 'EU', 'product_type' => 'industrial_battery', 'passport_level' => 'model', 'assessment_source' => 'Missing capacity']],
    [['market' => 'EU', 'product_type' => 'electrical', 'passport_level' => 'model', 'assessment_source' => '   ']],
    [['market' => 'EU', 'product_type' => 'ev_battery', 'passport_level' => 'item', 'assessment_source' => 'Test', 'company_id' => 1]],
]);
