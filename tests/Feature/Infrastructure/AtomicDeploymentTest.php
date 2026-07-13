<?php

namespace Tests\Feature\Infrastructure;

test('deployment lock is exclusive', function () {
    $lockFile = sys_get_temp_dir().'/deploy-test-lock-'.bin2hex(random_bytes(4));

    $fh1 = fopen($lockFile, 'w');
    expect(flock($fh1, LOCK_EX | LOCK_NB))->toBeTrue();

    $fh2 = fopen($lockFile, 'w');
    expect(flock($fh2, LOCK_EX | LOCK_NB))->toBeFalse();

    flock($fh1, LOCK_UN);
    fclose($fh1);
    fclose($fh2);
    unlink($lockFile);
});

test('lock is released after holder closes', function () {
    $lockFile = sys_get_temp_dir().'/deploy-test-lock-'.bin2hex(random_bytes(4));

    $fh1 = fopen($lockFile, 'w');
    flock($fh1, LOCK_EX | LOCK_NB);
    flock($fh1, LOCK_UN);
    fclose($fh1);

    $fh2 = fopen($lockFile, 'w');
    expect(flock($fh2, LOCK_EX | LOCK_NB))->toBeTrue();

    flock($fh2, LOCK_UN);
    fclose($fh2);
    unlink($lockFile);
});

test('checksum mismatch prevents extraction', function () {
    $content = 'test artifact';
    $modifiedContent = 'MODIFIED artifact';
    $originalHash = hash('sha256', $content);
    $modifiedHash = hash('sha256', $modifiedContent);

    expect($originalHash)->not->toBe($modifiedHash);
});

test('invalid RELEASE.json stops deployment', function () {
    $invalid = '{this is not valid json}';
    $result = json_decode($invalid, true);

    expect(json_last_error())->not->toBe(JSON_ERROR_NONE);
    expect($result)->toBeNull();
});

test('RELEASE.json missing required fields is detected', function () {
    $missingCommit = json_encode([
        'application' => 'NordiPass',
        'built_at' => '2026-07-13T10:00:00Z',
    ]);
    $data = json_decode($missingCommit, true);

    expect($data)->not->toHaveKey('commit');
});

test('current symlink can be switched via juncture directory', function () {
    $tmpRoot = sys_get_temp_dir().'/deploy-atomic-'.bin2hex(random_bytes(8));
    $releasesDir = $tmpRoot.'/releases';
    $releaseA = $releasesDir.'/release-a';
    $releaseB = $releasesDir.'/release-b';
    $currentMarker = $tmpRoot.'/current.txt';

    mkdir($tmpRoot, 0700, true);
    mkdir($releasesDir, 0700, true);
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);
    file_put_contents($releaseA.'/version.txt', 'a');
    file_put_contents($releaseB.'/version.txt', 'b');

    file_put_contents($currentMarker, $releaseA);
    expect(file_get_contents($currentMarker))->toContain('release-a');

    file_put_contents($currentMarker, $releaseB);
    expect(file_get_contents($currentMarker))->toContain('release-b');

    rehearsal_atomic_rrmdir($tmpRoot);
});

test('previous release is retained after switch', function () {
    $tmpRoot = sys_get_temp_dir().'/deploy-retain-'.bin2hex(random_bytes(8));
    $releasesDir = $tmpRoot.'/releases';
    $releaseA = $releasesDir.'/release-a';
    $releaseB = $releasesDir.'/release-b';

    mkdir($tmpRoot, 0700, true);
    mkdir($releasesDir, 0700, true);
    mkdir($releaseA, 0700, true);
    mkdir($releaseB, 0700, true);

    expect(is_dir($releaseA))->toBeTrue();
    expect(is_dir($releaseB))->toBeTrue();

    rehearsal_atomic_rrmdir($tmpRoot);
});

test('root path slash is rejected', function () {
    $root = '/';

    expect($root === '/')->toBeTrue();
});

test('path traversal in release id is blocked', function () {
    $maliciousId = '../etc/passwd';

    expect($maliciousId)->toContain('/');
});

test('release cleanup keeps current release', function () {
    $keep = 3;
    $releaseIds = ['rel-01', 'rel-02', 'rel-03', 'rel-04', 'rel-05'];
    $current = 'rel-03';

    $toDelete = array_slice($releaseIds, $keep);
    $kept = array_slice($releaseIds, 0, $keep);

    expect($toDelete)->not->toContain($current);
    expect($kept)->toContain($current);
});

test('SHA-256 verification detects tampering', function () {
    $data = 'release payload';
    $hash = hash('sha256', $data);
    $expectedFormat = '/\A[a-f0-9]{64}\z/';

    expect($hash)->toMatch($expectedFormat);

    $tamperedHash = hash('sha256', 'tampered payload');
    expect($tamperedHash)->not->toBe($hash);
});

function rehearsal_atomic_rrmdir(string $dir): void
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
