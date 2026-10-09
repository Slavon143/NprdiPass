<?php

// Installed in shared/storage/framework/maintenance.php by the site installer.
// public/index.php loads this before Composer or application/session bootstrap.
// Keep the lease alive through PHP shutdown; do not register an early unlock.
$nordipassGateBase = '/home/codecraf/nordipass-staging';
$nordipassGateDirectory = $nordipassGateBase.'/shared/storage/framework/nordipass-writer-gate';
$nordipassGateReject = static function (): never {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    header('X-NordiPass-Writer-Gate: v1');
    echo 'NordiPass is temporarily unavailable during a staging release.';
    exit;
};
if (realpath(__DIR__) !== $nordipassGateBase.'/shared/storage/framework'
    || ! is_dir($nordipassGateDirectory.'/seen') || is_link($nordipassGateDirectory)) {
    $nordipassGateReject();
}
$nordipassGateStat = @file_get_contents('/proc/self/stat');
$nordipassGateFields = is_string($nordipassGateStat)
    ? explode(' ', trim(substr($nordipassGateStat, strrpos($nordipassGateStat, ')') + 1))) : [];
$nordipassGateStart = $nordipassGateFields[19] ?? '';
if (! ctype_digit($nordipassGateStart)) {
    $nordipassGateReject();
}
// Seeing the same PID/start identity at this entry proves any earlier request
// in this single-request PHP process has ended, including pre-install requests.
$nordipassGateSeen = $nordipassGateDirectory.'/seen/'.getmypid().'-'.$nordipassGateStart;
if (! is_file($nordipassGateSeen)) {
    $nordipassGatePreviousMask = umask(0077);
    $nordipassGateWritten = @file_put_contents($nordipassGateSeen, 'v1', LOCK_EX);
    umask($nordipassGatePreviousMask);
    if ($nordipassGateWritten === false) {
        $nordipassGateReject();
    }
}
if (file_exists($nordipassGateBase.'/shared/writers-paused')) {
    $nordipassGateReject();
}
$nordipassHttpWriterLeaseV1 = @fopen($nordipassGateDirectory.'/http.lock', 'r+');
if ($nordipassHttpWriterLeaseV1 === false || ! flock($nordipassHttpWriterLeaseV1, LOCK_SH)) {
    $nordipassGateReject();
}
clearstatcache(true, $nordipassGateBase.'/shared/writers-paused');
if (file_exists($nordipassGateBase.'/shared/writers-paused')) {
    $nordipassGateReject();
}
