<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('HTTP request ID is available in queued notification context', function () {
    Mail::fake();
    $requestId = (string) Str::uuid();

    $this->withHeaders(['X-Request-ID' => $requestId])
        ->get('/up');

    expect(Context::get('request_id'))->toBe($requestId);
});

test('Context does not leak between sequential jobs', function () {
    Mail::fake();

    $firstId = (string) Str::uuid();
    $this->withHeaders(['X-Request-ID' => $firstId])->get('/ready');
    $firstContext = Context::get('request_id');

    $secondId = (string) Str::uuid();
    Context::add('request_id', $secondId);
    $secondContext = Context::get('request_id');

    expect($firstContext)->not->toBe($secondContext);
});

test('sensitive headers are not stored in request Context', function () {
    $requestId = (string) Str::uuid();

    $this->withHeaders([
        'X-Request-ID' => $requestId,
        'Authorization' => 'Bearer test-token-12345',
        'Cookie' => 'session=abc123',
    ])->get('/up');

    expect(Context::get('request_id'))->toBe($requestId);

    $context = Context::all();
    expect($context)->not->toHaveKey('Authorization');
});

test('Context is scoped per request and does not persist across requests', function () {
    $firstId = (string) Str::uuid();
    $this->withHeaders(['X-Request-ID' => $firstId])->get('/ready');

    $secondId = (string) Str::uuid();
    $this->withHeaders(['X-Request-ID' => $secondId])->get('/ready');

    expect(Context::get('request_id'))->toBe($secondId);
});
