<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Context;

test('valid incoming UUID is accepted', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $response = $this->get('/up', ['X-Request-ID' => $uuid]);

    $response->assertHeader('X-Request-ID', $uuid);
});

test('valid alphanumeric request ID is accepted', function () {
    $id = 'req-123_456.789';

    $response = $this->get('/up', ['X-Request-ID' => $id]);

    $response->assertHeader('X-Request-ID', $id);
});

test('invalid request ID is replaced', function () {
    $response = $this->get('/up', ['X-Request-ID' => '<script>']);

    $header = $response->headers->get('X-Request-ID');
    expect($header)->not->toBe('<script>');
});

test('oversized request ID is replaced', function () {
    $response = $this->get('/up', ['X-Request-ID' => str_repeat('a', 200)]);

    $header = $response->headers->get('X-Request-ID');
    expect(strlen((string) $header))->toBeLessThan(200);
});

test('missing request ID generates a new UUID', function () {
    $response = $this->get('/up');

    $header = $response->headers->get('X-Request-ID');
    expect($header)->toMatch('/\A[0-9a-f-]{36}\z/');
});

test('two requests do not share the same request ID', function () {
    $first = $this->get('/up');
    $second = $this->get('/up');

    expect($first->headers->get('X-Request-ID'))
        ->not->toBe($second->headers->get('X-Request-ID'));
});

test('request ID is available in Context for queued jobs', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $this->get('/ready', ['X-Request-ID' => $uuid]);

    expect(Context::get('request_id'))->toBe($uuid);
});

test('health response contains X-Request-ID header', function () {
    $response = $this->getJson('/ready');

    $response->assertHeader('X-Request-ID');
});
