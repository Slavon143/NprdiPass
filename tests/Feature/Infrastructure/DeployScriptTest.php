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

test('deploy workflow downloads the artifact from an explicit release run', function () {
    $workflow = file_get_contents(base_path('.github/workflows/deploy-production.yml'));

    expect($workflow)
        ->toContain('release_run_id:')
        ->toContain('run-id: ${{ inputs.release_run_id }}')
        ->toContain('github-token: ${{ secrets.GITHUB_TOKEN }}')
        ->toContain('actions: read');
});

test('deploy script fails the release when health checks do not pass', function () {
    $script = file_get_contents(base_path('deploy/scripts/deploy.sh'));

    expect($script)
        ->toContain('up_ok=false')
        ->toContain('ready_ok=false')
        ->toContain('Deployment health checks failed')
        ->toContain('exit 5');
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
