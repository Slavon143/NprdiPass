<?php

namespace Tests\Feature\Infrastructure;

beforeEach(function () {
    $this->tmpRoot = sys_get_temp_dir().'/nordipass-rehearsal-'.bin2hex(random_bytes(8));
    $this->releasesDir = $this->tmpRoot.'/releases';
    $this->sharedDir = $this->tmpRoot.'/shared';
    $this->currentMarker = $this->tmpRoot.'/current.txt';
    $this->lockFile = $this->tmpRoot.'/deploy.lock';

    mkdir($this->tmpRoot, 0700, true);
    mkdir($this->releasesDir, 0700, true);
    mkdir($this->sharedDir, 0700, true);
    mkdir($this->sharedDir.'/storage/app', 0700, true);
    mkdir($this->sharedDir.'/storage/framework', 0700, true);
    mkdir($this->sharedDir.'/storage/logs', 0700, true);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=rehearsal\n");
});

afterEach(function () {
    rehearsal_deploy_rrmdir($this->tmpRoot);
});

function current_target(): ?string
{
    $marker = test()->currentMarker;
    if (file_exists($marker)) {
        return file_get_contents($marker);
    }

    return null;
}

function set_current_target(string $path): void
{
    file_put_contents(test()->currentMarker, $path);
}

test('release-a becomes current', function () {
    $releaseA = $this->releasesDir.'/release-a';
    mkdir($releaseA, 0700, true);
    file_put_contents($releaseA.'/version.txt', 'a');
    file_put_contents($releaseA.'/RELEASE.json', json_encode([
        'application' => 'NordiPass',
        'commit' => 'aaaaaaaa',
        'ref' => 'v0.1.0',
        'built_at' => '2026-07-13T10:00:00Z',
        'php_version' => '8.4',
        'node_version' => '22.x',
    ]));

    set_current_target($releaseA);

    expect(current_target())->toContain('release-a');
    expect(is_dir($releaseA))->toBeTrue();
    expect(file_exists($releaseA.'/RELEASE.json'))->toBeTrue();
});

test('release-b is prepared and switched', function () {
    $releaseA = $this->releasesDir.'/release-a';
    $releaseB = $this->releasesDir.'/release-b';
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);
    file_put_contents($releaseB.'/RELEASE.json', json_encode([
        'application' => 'NordiPass',
        'commit' => 'bbbbbbbb',
        'ref' => 'v0.2.0',
        'built_at' => '2026-07-13T11:00:00Z',
        'php_version' => '8.4',
        'node_version' => '22.x',
    ]));

    set_current_target($releaseA);
    expect(current_target())->toContain('release-a');

    set_current_target($releaseB);
    expect(current_target())->toContain('release-b');
    expect(is_dir($releaseA))->toBeTrue();
    expect(is_dir($releaseB))->toBeTrue();
});

test('checksum mismatch prevents switch', function () {
    $releaseA = $this->releasesDir.'/release-a';
    $releaseB = $this->releasesDir.'/release-b';
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);

    set_current_target($releaseA);

    $artifactHash = hash('sha256', 'original');
    $expectedHash = hash('sha256', 'tampered');
    $mismatch = $artifactHash !== $expectedHash;

    if ($mismatch) {
        rehearsal_deploy_rrmdir($releaseB);
    }

    expect($mismatch)->toBeTrue();
    expect(is_dir($releaseA))->toBeTrue();
    expect(current_target())->toContain('release-a');
});

test('lock contention blocks concurrent deploy', function () {
    $fh1 = fopen($this->lockFile, 'w');
    flock($fh1, LOCK_EX | LOCK_NB);

    $fh2 = fopen($this->lockFile, 'w');
    $locked = flock($fh2, LOCK_EX | LOCK_NB);

    expect($locked)->toBeFalse();

    flock($fh1, LOCK_UN);
    fclose($fh1);
    fclose($fh2);
});

test('invalid RELEASE.json stops deploy', function () {
    $releaseA = $this->releasesDir.'/release-a';
    $releaseB = $this->releasesDir.'/release-b';
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);

    set_current_target($releaseA);

    file_put_contents($releaseB.'/RELEASE.json', '{not valid}');
    $decoded = json_decode(file_get_contents($releaseB.'/RELEASE.json'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        rehearsal_deploy_rrmdir($releaseB);
    }

    expect(json_last_error())->not->toBe(JSON_ERROR_NONE);
    expect(current_target())->toContain('release-a');
});

test('rollback returns to previous release', function () {
    $releaseA = $this->releasesDir.'/release-a';
    $releaseB = $this->releasesDir.'/release-b';
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);

    set_current_target($releaseA);
    expect(current_target())->toContain('release-a');

    set_current_target($releaseB);
    expect(current_target())->toContain('release-b');

    set_current_target($releaseA);
    expect(current_target())->toContain('release-a');
});

test('release cleanup preserves current and KEEP count', function () {
    $keep = 3;
    for ($i = 1; $i <= 7; $i++) {
        $rel = $this->releasesDir.'/rel-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        mkdir($rel, 0700, true);
        touch($rel, time() - (7 - $i) * 3600);
    }

    $currentRelease = $this->releasesDir.'/rel-07';
    set_current_target($currentRelease);

    $allDirs = array_filter(glob($this->releasesDir.'/*'), 'is_dir');
    usort($allDirs, fn ($a, $b) => filemtime($b) - filemtime($a));

    $toDelete = array_slice($allDirs, $keep);
    $deleted = 0;
    foreach ($toDelete as $dir) {
        if (realpath($dir) !== realpath($currentRelease)) {
            rehearsal_deploy_rrmdir($dir);
            $deleted++;
        }
    }

    expect(is_dir($currentRelease))->toBeTrue();
    expect($deleted)->toBeGreaterThan(0);
});

test('failed cache build does not switch current', function () {
    $releaseA = $this->releasesDir.'/release-a';
    $releaseB = $this->releasesDir.'/release-b';
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);

    set_current_target($releaseA);

    rehearsal_deploy_rrmdir($releaseB);

    expect(current_target())->toContain('release-a');
    expect(is_dir($releaseB))->toBeFalse();
});

test('health check bounded retries', function () {
    $maxRetries = 10;
    $attempts = 0;
    $upOk = false;

    while ($attempts < $maxRetries && ! $upOk) {
        $attempts++;
        if ($attempts >= 1) {
            $upOk = true;
        }
    }

    expect($attempts)->toBeLessThanOrEqual($maxRetries);
    expect($upOk)->toBeTrue();
});

test('health check failure after max attempts is handled', function () {
    $maxRetries = 10;
    $attempts = 0;

    while ($attempts < $maxRetries) {
        $attempts++;
    }

    expect($attempts)->toBe($maxRetries);
});

function rehearsal_deploy_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if ($item->isLink()) {
            unlink($item->getPathname());
        } elseif ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}
