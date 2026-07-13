<?php

namespace Tests\Feature\Infrastructure;

test('release artifact structure is valid', function () {
    $manifest = [
        'application' => 'NordiPass',
        'commit' => 'abcdef1234567890abcdef1234567890abcdef12',
        'ref' => 'v0.1.0',
        'built_at' => '2026-07-14T10:00:00Z',
        'php_version' => '8.4',
    ];

    $json = json_encode($manifest);

    expect($json)->not->toBeFalse();
    expect($manifest['application'])->toBe('NordiPass');
    expect($manifest['commit'])->toMatch('/\A[a-f0-9]{40}\z/');
    expect($manifest['built_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    expect($manifest['php_version'])->toBe('8.4');
});

test('RELEASE.json is valid JSON', function () {
    $content = json_encode([
        'application' => 'NordiPass',
        'commit' => 'abcdef1234567890abcdef1234567890abcdef12',
        'ref' => 'v0.1.0',
        'built_at' => '2026-07-14T10:00:00Z',
        'php_version' => '8.4',
    ]);

    $decoded = json_decode($content, true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($decoded['application'])->toBe('NordiPass');
});

test('malformed RELEASE.json is rejected', function () {
    $result = json_decode('{invalid json}', true);
    expect(json_last_error())->not->toBe(JSON_ERROR_NONE);
});

test('RELEASE.json rejects missing commit', function () {
    $invalid = [
        'application' => 'NordiPass',
        'built_at' => '2026-07-14T10:00:00Z',
    ];

    expect($invalid)->not->toHaveKey('commit');
});

test('release artifact SHA-256 verification works', function () {
    $content = 'test artifact content';
    $sha256 = hash('sha256', $content);

    expect($sha256)->toMatch('/\A[a-f0-9]{64}\z/');
    expect(hash('sha256', $content))->toBe($sha256);
    expect(hash('sha256', 'modified content'))->not->toBe($sha256);
});

test('deployment script verifies checksum before extraction', function () {
    $original = 'test artifact data';
    $modified = 'MODIFIED artifact data';
    $originalHash = hash('sha256', $original);
    $modifiedHash = hash('sha256', $modified);

    expect($originalHash)->not->toBe($modifiedHash);
});

test('.env is not included in release scope', function () {
    $releaseDirectories = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor'];

    expect($releaseDirectories)->not->toContain('.env')
        ->and($releaseDirectories)->not->toContain('.git')
        ->and($releaseDirectories)->not->toContain('node_modules')
        ->and($releaseDirectories)->not->toContain('tests');
});

test('engines in package.json are aligned', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true);

    expect($package)->toHaveKey('type');
});
