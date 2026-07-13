<?php

namespace Tests\Feature\Infrastructure;

test('deploy script exists', function () {
    expect(base_path('deploy/scripts/deploy.sh'))->toBeFile();
});

test('rollback script exists', function () {
    expect(base_path('deploy/scripts/rollback.sh'))->toBeFile();
});

test('deploy workflow exists', function () {
    expect(base_path('.github/workflows/ci.yml'))->toBeFile();
});

test('release workflow exists', function () {
    expect(base_path('.github/workflows/release.yml'))->toBeFile();
});

test('deploy production workflow exists', function () {
    expect(base_path('.github/workflows/deploy-production.yml'))->toBeFile();
});

test('nvmrc exists with Node version', function () {
    expect(base_path('.nvmrc'))->toBeFile();

    $version = trim(file_get_contents(base_path('.nvmrc')));
    expect($version)->toMatch('/^\d{2}$/');
});

test('release checklist exists', function () {
    expect(base_path('docs/infrastructure/RELEASE_CHECKLIST.md'))->toBeFile();
});

test('CI documentation exists', function () {
    expect(base_path('docs/infrastructure/CI_AND_DEPLOYMENT.md'))->toBeFile();
});
