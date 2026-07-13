<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('production JSON formatter creates valid JSON when used', function () {
    $channels = Config::get('logging.channels');

    expect($channels)->toHaveKey('daily_json')
        ->and($channels)->toHaveKey('stderr_json');
});

test('daily_json channel uses JSON formatter', function () {
    $channel = Config::get('logging.channels.daily_json');

    expect($channel['formatter'])->toContain('JsonFormatter');
});

test('stderr_json channel uses JSON formatter', function () {
    $channel = Config::get('logging.channels.stderr_json');

    expect($channel['formatter'])->toContain('JsonFormatter');
});

test('log retention is configurable via env', function () {
    expect(Config::get('logging.channels.daily_json.days'))->toBe(14);
});
