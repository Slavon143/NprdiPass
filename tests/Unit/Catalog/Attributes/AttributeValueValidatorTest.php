<?php

use App\Enums\Catalog\AttributeDataType;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Support\Catalog\AttributeValueValidator;

test('validation rule allowlist normalizes every supported rule family', function (AttributeDataType $type, array $rules, array $expected) {
    expect((new AttributeValueValidator)->normalizeRules($type, $rules))->toBe($expected);
})->with([
    'text' => [AttributeDataType::Text, ['min_length' => '1', 'max_length' => '1000'], ['min_length' => 1, 'max_length' => 1000]],
    'integer' => [AttributeDataType::Integer, ['min' => '-10', 'max' => '10'], ['min' => '-10', 'max' => '10']],
    'decimal' => [AttributeDataType::Decimal, ['min' => '0.0001', 'max' => '9.9999'], ['min' => '0.0001', 'max' => '9.9999']],
    'date' => [AttributeDataType::Date, ['min_date' => '2020-01-01', 'max_date' => '2030-12-31'], ['min_date' => '2020-01-01', 'max_date' => '2030-12-31']],
    'multiselect' => [AttributeDataType::Multiselect, ['min_selections' => '0', 'max_selections' => '200'], ['min_selections' => 0, 'max_selections' => 200]],
]);

test('select and boolean reject arbitrary validation rules', function (AttributeDataType $type) {
    expect(fn () => (new AttributeValueValidator)->normalizeRules($type, ['min' => 1]))
        ->toThrow(AttributeOperationException::class, 'not allowed');
})->with([AttributeDataType::Select, AttributeDataType::Boolean]);

test('blank rules from the shared definition form are ignored before the type allowlist is applied', function () {
    $rules = [
        'min_length' => '',
        'max_length' => null,
        'min' => '',
        'max' => null,
        'min_date' => '',
        'max_date' => null,
        'min_selections' => '',
        'max_selections' => null,
    ];

    expect((new AttributeValueValidator)->normalizeRules(AttributeDataType::Select, $rules))->toBe([]);
});

test('validation rule bounds and minimum maximum order are enforced', function (AttributeDataType $type, array $rules) {
    expect(fn () => (new AttributeValueValidator)->normalizeRules($type, $rules))
        ->toThrow(AttributeOperationException::class);
})->with([
    [AttributeDataType::Text, ['max_length' => 1001]],
    [AttributeDataType::Multiselect, ['max_selections' => 201]],
    [AttributeDataType::Integer, ['min' => 2, 'max' => 1]],
    [AttributeDataType::Date, ['min_date' => '2026-02-30']],
]);
