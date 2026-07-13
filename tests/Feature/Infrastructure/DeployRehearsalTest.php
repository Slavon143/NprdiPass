<?php

namespace Tests\Feature\Infrastructure;

function deploy_rehearsal_rrmdir(string $dir): void
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

beforeEach(function () {
    $this->tmpRoot = sys_get_temp_dir().'/nordipass-deploy-test-'.bin2hex(random_bytes(8));
    $this->releasesDir = $this->tmpRoot.'/releases';
    $this->currentLink = $this->tmpRoot.'/current';

    mkdir($this->tmpRoot, 0700, true);
    mkdir($this->releasesDir, 0700, true);
});

afterEach(function () {
    if (is_dir($this->tmpRoot)) {
        deploy_rehearsal_rrmdir($this->tmpRoot);
    }
});

test('deploy script file exists and is readable', function () {
    $path = base_path('deploy/scripts/deploy.sh');

    expect($path)->toBeFile()
        ->and(filesize($path))->toBeGreaterThan(0);
});

test('rollback script file exists and is readable', function () {
    $path = base_path('deploy/scripts/rollback.sh');

    expect($path)->toBeFile()
        ->and(filesize($path))->toBeGreaterThan(0);
});

test('release cleanup retains configured count', function () {
    for ($i = 1; $i <= 7; $i++) {
        $rel = $this->releasesDir.'/rel-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        mkdir($rel, 0700, true);
    }

    $currentRelease = $this->releasesDir.'/rel-07';
    $keep = 5;
    $allReleases = glob($this->releasesDir.'/*', GLOB_ONLYDIR);
    usort($allReleases, fn ($a, $b) => filemtime($b) - filemtime($a));

    $toDelete = array_slice($allReleases, $keep);
    foreach ($toDelete as $dir) {
        if (realpath($dir) !== realpath($currentRelease)) {
            deploy_rehearsal_rrmdir($dir);
        }
    }

    $remaining = glob($this->releasesDir.'/*', GLOB_ONLYDIR);
    expect(is_dir($currentRelease))->toBeTrue()
        ->and(count($remaining))->toBeLessThanOrEqual(6);
});

test('release cleanup preserves current release', function () {
    for ($i = 1; $i <= 3; $i++) {
        $rel = $this->releasesDir.'/rel-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        mkdir($rel, 0700, true);
    }

    $currentRelease = $this->releasesDir.'/rel-02';
    file_put_contents($currentRelease.'/marker.txt', 'current');

    $allReleases = glob($this->releasesDir.'/*', GLOB_ONLYDIR);
    foreach ($allReleases as $dir) {
        if (realpath($dir) !== realpath($currentRelease) && basename($dir) !== 'rel-01') {
            deploy_rehearsal_rrmdir($dir);
        }
    }

    expect(is_dir($currentRelease))->toBeTrue();
});

test('deployment lock prevents concurrent deploy', function () {
    $lockFile = $this->tmpRoot.'/deploy.lock';

    $fh = fopen($lockFile, 'w');
    flock($fh, LOCK_EX);

    $secondLock = fopen($lockFile, 'w');
    $locked = flock($secondLock, LOCK_EX | LOCK_NB);

    expect($locked)->toBeFalse();

    flock($fh, LOCK_UN);
    fclose($fh);
    fclose($secondLock);
});

test('deployment lock is released after completion', function () {
    $lockFile = $this->tmpRoot.'/deploy.lock';

    $fh = fopen($lockFile, 'w');
    flock($fh, LOCK_EX);
    flock($fh, LOCK_UN);
    fclose($fh);

    $secondFh = fopen($lockFile, 'w');
    $locked = flock($secondFh, LOCK_EX | LOCK_NB);
    expect($locked)->toBeTrue();
    flock($secondFh, LOCK_UN);
    fclose($secondFh);
});

test('deploy script shebang is bash', function () {
    $content = file_get_contents(base_path('deploy/scripts/deploy.sh'));
    expect(explode("\n", $content)[0])->toContain('#!/usr/bin/env bash');
});

test('rollback script shebang is bash', function () {
    $content = file_get_contents(base_path('deploy/scripts/rollback.sh'));
    expect(explode("\n", $content)[0])->toContain('#!/usr/bin/env bash');
});

test('custom function rrmdir works', function () {
    $testDir = sys_get_temp_dir().'/rrmdir-test-'.bin2hex(random_bytes(4));
    mkdir($testDir, 0700, true);
    mkdir($testDir.'/sub', 0700, true);
    file_put_contents($testDir.'/sub/file.txt', 'test');

    deploy_rehearsal_rrmdir($testDir);

    expect(is_dir($testDir))->toBeFalse();
});
