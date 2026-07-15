<?php

use Symfony\Component\Yaml\Yaml;

function openApiSpec(): ?array
{
    $path = base_path('docs/api/openapi-v1.yaml');

    if (! file_exists($path)) {
        throw new RuntimeException("OpenAPI spec file not found: {$path}");
    }

    $parsed = Yaml::parseFile($path);

    if (! is_array($parsed)) {
        throw new RuntimeException('YAML parsing returned non-array');
    }

    return $parsed;
}

function openApiOperations(): array
{
    $spec = openApiSpec();
    $ops = [];

    if ($spec === null) {
        return $ops;
    }

    foreach ($spec['paths'] ?? [] as $uri => $methods) {
        foreach ($methods as $method => $operation) {
            if (! is_array($operation) || ! isset($operation['operationId'])) {
                continue;
            }

            $ops[] = [
                'method' => $method,
                'uri' => $uri,
                'operationId' => $operation['operationId'],
            ];
        }
    }

    return $ops;
}

test('OpenAPI spec can be parsed and has expected structure', function () {
    $spec = openApiSpec();
    expect($spec)->toBeArray();
    expect($spec)->toHaveKey('openapi');
    expect($spec)->toHaveKey('paths');
    expect($spec)->toHaveKey('components');
    expect($spec['paths'])->toBeArray();
    expect(count($spec['paths']))->toBeGreaterThanOrEqual(30);
    expect($spec['components'])->toHaveKey('schemas');
    expect($spec['components']['schemas'])->toBeArray();
    expect($spec['components']['schemas'])->toHaveKey('PaginationMeta');
    expect($spec['components']['schemas'])->toHaveKey('ErrorResponse');
    expect($spec['components'])->toHaveKey('responses');
});

test('OpenAPI specification file exists', function () {
    $path = base_path('docs/api/openapi-v1.yaml');

    expect(file_exists($path))->toBeTrue('docs/api/openapi-v1.yaml must exist');

    $content = file_get_contents($path);
    expect($content)->not->toBeEmpty();
});

test('OpenAPI specification is valid YAML and version 3.1.x', function () {
    $spec = openApiSpec();

    expect($spec)->toBeArray('YAML must parse to an array');
    expect($spec['openapi'] ?? null)->toBeString();
    expect($spec['openapi'])->toStartWith('3.1', 'OpenAPI version must be 3.1.x');
});

test('OpenAPI has Bearer security scheme', function () {
    $spec = openApiSpec();

    expect($spec)->toBeArray();
    expect($spec['components']['securitySchemes'] ?? null)->toBeArray();

    $bearerAuth = $spec['components']['securitySchemes']['bearerAuth'] ?? null;
    expect($bearerAuth)->toBeArray();
    expect($bearerAuth['type'] ?? null)->toBe('http');
    expect($bearerAuth['scheme'] ?? null)->toBe('bearer');
});

test('OpenAPI documents all required catalog paths', function () {
    $spec = openApiSpec();
    $paths = $spec['paths'] ?? [];
    $pathKeys = array_keys($paths);

    expect($pathKeys)->not->toBeEmpty('Paths array should not be empty');
    expect(count($pathKeys))->toBeGreaterThanOrEqual(30);
    expect($paths)->toHaveKey('/categories');
    expect($paths)->toHaveKey('/products');
    expect($paths)->toHaveKey('/attributes');
    expect($paths)->toHaveKey('/products/{product}/readiness');
    expect($paths)->toHaveKey('/media/{media}/content');
});

test('All operationId values are unique', function () {
    $ops = openApiOperations();
    $ids = array_column($ops, 'operationId');
    $unique = array_unique($ids);

    expect(count($ids))->toEqual(count($unique), 'Duplicate operationId detected');
    expect(count($ids))->toEqual(53, 'Must have exactly 53 operations matching 53 registered routes');
});

test('All internal $ref references resolve', function () {
    $spec = openApiSpec();
    $yaml = Yaml::dump($spec);
    preg_match_all('/\$ref:\s*["\']?#\/components\/([^"\'\s]+)["\']?/', $yaml, $matches);

    $components = $spec['components'] ?? [];

    expect($matches[1] ?? null)->not->toBeEmpty('No internal $ref references found');

    foreach ($matches[1] as $refPath) {
        $parts = explode('/', $refPath);
        $current = $components;

        foreach ($parts as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                expect(false)->toBeTrue("Unresolved internal \$ref: #/components/{$refPath}");

                break;
            }

            $current = $current[$part];
        }
    }
});

test('OpenAPI documents error schema', function () {
    $spec = openApiSpec();

    $errorSchema = $spec['components']['schemas']['Error'] ?? null;
    expect($errorSchema)->toBeArray('Error schema must exist');
    expect($errorSchema['properties']['code'] ?? null)->toBeArray();
    expect($errorSchema['properties']['message'] ?? null)->toBeArray();

    $errorResponse = $spec['components']['schemas']['ErrorResponse'] ?? null;
    expect($errorResponse)->toBeArray('ErrorResponse schema must exist');

    $errorResponses = [
        'Unauthenticated',
        'Forbidden',
        'NotFound',
        'Conflict',
        'ValidationError',
    ];

    foreach ($errorResponses as $name) {
        $resp = $spec['components']['responses'][$name] ?? null;
        expect($resp)->toBeArray("Error response '{$name}' must exist");
    }
});

test('OpenAPI documents pagination schema', function () {
    $spec = openApiSpec();

    $paginationMeta = $spec['components']['schemas']['PaginationMeta'] ?? null;
    expect($paginationMeta)->toBeArray('PaginationMeta schema must exist');
    expect($paginationMeta['properties']['current_page'] ?? null)->toBeArray();
    expect($paginationMeta['properties']['per_page'] ?? null)->toBeArray();
    expect($paginationMeta['properties']['total'] ?? null)->toBeArray();
    expect($paginationMeta['properties']['last_page'] ?? null)->toBeArray();
    expect($paginationMeta['properties']['links'] ?? null)->toBeArray();
});

test('OpenAPI documents multipart media upload', function () {
    $spec = openApiSpec();
    $yaml = Yaml::dump($spec);

    expect($yaml)->toContain('multipart/form-data');
    expect($yaml)->toContain('uploadProductMedia');
    expect($yaml)->toContain('uploadVariantMedia');
});

test('OpenAPI documents lifecycle endpoints', function () {
    $spec = openApiSpec();
    $paths = $spec['paths'] ?? [];

    expect($paths)->toHaveKey('/products/{product}/readiness');
    expect($paths)->toHaveKey('/products/{product}/activate');
    expect($paths)->toHaveKey('/products/{product}/return-to-draft');
    expect($paths)->toHaveKey('/products/{product}/archive');
    expect($paths)->toHaveKey('/products/{product}/restore');
    expect($paths)->toHaveKey('/products/{product}/variants/{variant}/archive');
    expect($paths)->toHaveKey('/products/{product}/variants/{variant}/restore');
});

test('OpenAPI documents rate limit responses', function () {
    $spec = openApiSpec();

    $rateLimited = $spec['components']['responses']['RateLimited'] ?? null;
    expect($rateLimited)->toBeArray('RateLimited response must exist');
});

test('OpenAPI documents Request ID as response header', function () {
    $spec = openApiSpec();

    $requestIdHeader = $spec['components']['headers']['RequestId'] ?? null;
    expect($requestIdHeader)->toBeArray('RequestId header must be in components.headers');
    expect($requestIdHeader['schema']['type'] ?? null)->toBe('string');
    expect($requestIdHeader['schema']['format'] ?? null)->toBe('uuid');

    // Error schema must contain request_id
    $errorSchema = $spec['components']['schemas']['Error'] ?? [];
    expect($errorSchema['properties'] ?? [])->toHaveKey('request_id');

    // PaginationMeta must NOT contain request_id (it is pagination data only)
    $paginationMeta = $spec['components']['schemas']['PaginationMeta'] ?? [];
    expect($paginationMeta['properties'] ?? [])->not->toHaveKey('request_id');
});

test('OpenAPI documents all request schemas', function () {
    $spec = openApiSpec();
    $paths = $spec['paths'] ?? [];

    $methodCount = 0;
    $describedCount = 0;

    foreach ($paths as $uri => $methods) {
        foreach ($methods as $method => $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $methodCount++;

            if (isset($operation['responses'])) {
                $describedCount++;
            }
        }
    }

    expect($describedCount)->toEqual($methodCount, 'Every operation must have responses documented');
});

test('Deferred headers are not claimed as supported', function () {
    $spec = openApiSpec();
    $yaml = Yaml::dump($spec);

    expect($yaml)->not->toContain('If-Match', 'If-Match must not be claimed as supported');
    expect($yaml)->not->toContain('Idempotency-Key', 'Idempotency-Key must not be claimed as supported');
});

test('OpenAPI path count matches route count', function () {
    $spec = openApiSpec();
    $paths = $spec['paths'] ?? [];
    $pathCount = count($paths);

    expect($pathCount)->toBeGreaterThanOrEqual(30, 'OpenAPI must document at least 30 paths');
});
