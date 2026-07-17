<?php

use Illuminate\Support\Facades\Route;

test('OpenAPI documents every Stage 8 foundation route and Sanctum bearer scheme', function () {
    $path = base_path('docs/api/openapi-v1.yaml');

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)->toBeString()
        ->and($contents)->toContain('openapi: 3.1.0')
        ->and($contents)->toContain('scheme: bearer')
        ->and($contents)->toContain('bearerFormat: Sanctum personal access token')
        ->and($contents)->toContain('  /health:')
        ->and($contents)->toContain('  /me:')
        ->and($contents)->toContain('  /company:')
        ->and($contents)->toContain('  /company/members:')
        ->and($contents)->toContain('company.read')
        ->and($contents)->toContain('members.read');

    foreach ([
        'api.v1.health',
        'api.v1.me',
        'api.v1.company.show',
        'api.v1.company.members.index',
    ] as $routeName) {
        expect(Route::has($routeName))->toBeTrue();
    }

    expect($contents)->toContain('/products:')
        ->and($contents)->toContain('/documents:')
        ->and($contents)->toContain('/health:')
        ->and($contents)->toContain('/me:')
        ->and($contents)->toContain('/company:')
        ->and($contents)->toContain('/company/members:');
});

test('API guide documents isolation expiration revoke and rate limits', function () {
    $contents = file_get_contents(base_path('docs/API.md'));

    expect($contents)->toBeString()
        ->and($contents)->toContain('/api/v1')
        ->and($contents)->toContain('Authorization: Bearer YOUR_TOKEN')
        ->and($contents)->toContain('company_id')
        ->and($contents)->toContain('company.read')
        ->and($contents)->toContain('members.read')
        ->and($contents)->toContain('appears once')
        ->and($contents)->toContain('120 requests/minute per token ID')
        ->and($contents)->toContain('nordipass:prune-api-tokens');
});
