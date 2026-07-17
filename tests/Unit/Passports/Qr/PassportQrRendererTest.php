<?php

use App\Services\Passports\Qr\PassportQrRenderer;

test('svg is valid xml', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $svg = $renderer->renderSvg('test-id');

    $xml = simplexml_load_string($svg);
    expect($xml)->not()->toBeFalse();
    expect($xml->getName())->toBe('svg');
});

test('svg contains no script tag', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $svg = $renderer->renderSvg('test-id');

    expect($svg)->not()->toContain('<script');
    expect($svg)->not()->toContain('javascript:');
});

test('svg contains no external references', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $svg = $renderer->renderSvg('test-id');

    expect($svg)->not()->toContain('xlink:href="http');
    expect($svg)->not()->toContain('xlink:href="file');
});

test('svg contains no foreign object', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $svg = $renderer->renderSvg('test-id');

    expect($svg)->not()->toContain('foreignObject');
});

test('png has valid png signature', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $png = $renderer->renderPng('test-id');

    $signature = "\x89PNG\r\n\x1a\n";
    expect(str_starts_with($png, $signature))->toBeTrue();
});

test('png has reasonable minimum size', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $png = $renderer->renderPng('test-id');

    expect(strlen($png))->toBeGreaterThan(512);
});

test('png has expected minimum dimensions', function (): void {
    config()->set('passports.qr.download_size', 1024);

    $renderer = $this->app->make(PassportQrRenderer::class);
    $png = $renderer->renderPng('test-id');

    $info = getimagesizefromstring($png);
    expect($info[0])->toBeGreaterThanOrEqual(1024);
    expect($info[1])->toBeGreaterThanOrEqual(1024);
});

test('same input produces deterministic svg output', function (): void {
    config()->set('passports.qr.renderer_version', '1');

    $renderer = $this->app->make(PassportQrRenderer::class);

    $svg1 = $renderer->renderSvg('stable-id');
    $svg2 = $renderer->renderSvg('stable-id');

    expect(hash('sha256', $svg1))->toBe(hash('sha256', $svg2));
});

test('same input produces deterministic png output', function (): void {
    config()->set('passports.qr.renderer_version', '1');

    $renderer = $this->app->make(PassportQrRenderer::class);

    $png1 = $renderer->renderPng('stable-id');
    $png2 = $renderer->renderPng('stable-id');

    expect(hash('sha256', $png1))->toBe(hash('sha256', $png2));
});

test('etag is deterministic', function (): void {
    config()->set('passports.public_base_url', 'https://example.test');

    $renderer = $this->app->make(PassportQrRenderer::class);

    $etag1 = $renderer->eTag('my-id', 'svg');
    $etag2 = $renderer->eTag('my-id', 'svg');

    expect($etag1)->toBe($etag2);
});

test('etag changes with different format', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);

    expect($renderer->eTag('id', 'svg'))->not()->toBe($renderer->eTag('id', 'png'));
});

test('etag changes with different base url', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);

    config()->set('passports.public_base_url', 'https://a.test');
    $etag1 = $renderer->eTag('id', 'svg');

    config()->set('passports.public_base_url', 'https://b.test');
    $etag2 = $renderer->eTag('id', 'svg');

    expect($etag1)->not()->toBe($etag2);
});

test('cache key includes renderer version', function (): void {
    config()->set('passports.qr.renderer_version', 'v2');

    $renderer = $this->app->make(PassportQrRenderer::class);
    $key = $renderer->cacheKey('id', 'svg');

    expect($key)->toContain('v2');
});

test('cache key changes with renderer version', function (): void {
    $renderer = $this->app->make(PassportQrRenderer::class);

    config()->set('passports.qr.renderer_version', '1');
    $key1 = $renderer->cacheKey('id', 'svg');

    config()->set('passports.qr.renderer_version', '2');
    $key2 = $renderer->cacheKey('id', 'svg');

    expect($key1)->not()->toBe($key2);
});

test('renderer version 1 produces stable checksum', function (): void {
    config()->set('passports.qr.renderer_version', '1');
    config()->set('passports.public_base_url', 'https://qrcode.test');

    $renderer = $this->app->make(PassportQrRenderer::class);
    $svg = $renderer->renderSvg('public-id-abc');
    $png = $renderer->renderPng('public-id-abc');

    $hash1Svg = hash('sha256', $svg);
    $hash1Png = hash('sha256', $png);

    $svg2 = $renderer->renderSvg('public-id-abc');
    $png2 = $renderer->renderPng('public-id-abc');

    expect(hash('sha256', $svg2))->toBe($hash1Svg);
    expect(hash('sha256', $png2))->toBe($hash1Png);
});
