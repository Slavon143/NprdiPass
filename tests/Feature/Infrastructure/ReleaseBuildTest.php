<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;

test('executable release pipeline validates real archives excludes runtime data and preserves historical code', function () {
    $python = getenv('RELEASE_TEST_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $process = new Process([$python, '-m', 'unittest', 'discover', '-s', 'tests/Release', '-p', 'test_release_artifact.py', '-v'], base_path());
    $process->setEnv(['PYTHONDONTWRITEBYTECODE' => '1']);
    $process->setTimeout(120);
    $process->run();
    preg_match('/Ran (\d+) tests/', $process->getErrorOutput(), $summary);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput())
        ->and((int) ($summary[1] ?? 0))->toBeGreaterThanOrEqual(12)
        ->and($process->getErrorOutput())->toContain('OK')
        ->not->toContain('skipped');
});
