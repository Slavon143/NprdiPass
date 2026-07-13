<?php

use Illuminate\Support\Facades\Route;

test('OpenAPI documents every Stage 8 foundation route and Sanctum bearer scheme', function () {
    $contents = file_get_contents(base_path('docs/openapi.yaml'));

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

    expect($contents)->not->toContain('/products:')
        ->and($contents)->not->toContain('/documents:')
        ->and($contents)->not->toContain('/qr-codes:')
        ->and($contents)->not->toContain('/dpp:');
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
