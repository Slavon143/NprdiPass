<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->backupSandbox = storage_path('framework/testing/backup-restore/'.Str::uuid());
    $this->sourceRoots = [];
    foreach (['production_imports', 'product_documents', 'catalog_media', 'passport_assets'] as $disk) {
        $root = $this->backupSandbox.'/sources/'.$disk;
        mkdir($root, 0700, true);
        Config::set("filesystems.disks.{$disk}", ['driver' => 'local', 'root' => $root, 'throw' => true]);
        Storage::forgetDisk($disk);
        $this->sourceRoots[$disk] = $root;
    }

    Config::set('filesystems.disks.backup_test', [
        'driver' => 'local',
        'root' => $this->backupSandbox.'/backups',
        'throw' => true,
    ]);
    Config::set('backup.disk', 'backup_test');
    Config::set('backup.path', 'sets');
    Config::set('backup.temporary_path', $this->backupSandbox.'/temporary');
    Config::set('backup.files.disks', array_keys($this->sourceRoots));
    Config::set('backup.database.enabled', false);
    Config::set('backup.verify_after_create', true);
    Storage::forgetDisk('backup_test');

    file_put_contents($this->sourceRoots['production_imports'].'/source.csv', "sku,name\nA-1,Test\n");
    mkdir($this->sourceRoots['product_documents'].'/company/document', 0700, true);
    file_put_contents($this->sourceRoots['product_documents'].'/company/document/v1.pdf', '%PDF-real-document-bytes');
    mkdir($this->sourceRoots['catalog_media'].'/company/product', 0700, true);
    file_put_contents($this->sourceRoots['catalog_media'].'/company/product/image.png', 'real-catalog-image-bytes');
    mkdir($this->sourceRoots['passport_assets'].'/company/passport/version/media', 0700, true);
    file_put_contents($this->sourceRoots['passport_assets'].'/company/passport/version/media/asset.png', 'immutable-passport-asset-bytes');
});

afterEach(function () {
    foreach (['production_imports', 'product_documents', 'catalog_media', 'passport_assets', 'backup_test'] as $disk) {
        Storage::forgetDisk($disk);
    }
    removeBackupTestDirectory($this->backupSandbox);
    Cache::lock('nordipass:infrastructure:backup')->forceRelease();
});

function createPersistentFilesBackup(): array
{
    $exitCode = Artisan::call('nordipass:backup', ['--files-only' => true]);
    expect($exitCode)->toBe(0, Artisan::output());
    $disk = Storage::disk('backup_test');
    $directories = $disk->directories('sets');
    expect($directories)->toHaveCount(1);
    $id = basename($directories[0]);
    $manifest = json_decode($disk->get("sets/{$id}/manifest.json"), true, flags: JSON_THROW_ON_ERROR);

    return [$id, $manifest];
}

function removeBackupTestDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function addDatabaseArtifact(string $backupId, array $manifest): array
{
    $disk = Storage::disk('backup_test');
    $bytes = gzencode("CREATE TABLE recovery_drill (id INT);\n");
    $disk->put("sets/{$backupId}/database.sql.gz", $bytes);
    $manifest['scope']['database'] = true;
    $manifest['artifacts']['database'] = [
        'path' => 'database.sql.gz',
        'size' => strlen($bytes),
        'uncompressed_size' => strlen("CREATE TABLE recovery_drill (id INT);\n"),
        'sha256' => hash('sha256', $bytes),
    ];
    $disk->put("sets/{$backupId}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));

    return $manifest;
}

function fakeMysqlClient(string $sandbox): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $path = $sandbox.'/mysql-success.cmd';
        file_put_contents($path, "@echo off\r\nexit /b 0\r\n");

        return $path;
    }
    $path = $sandbox.'/mysql-success';
    file_put_contents($path, "#!/bin/sh\ncat >/dev/null\nexit 0\n");
    chmod($path, 0700);

    return $path;
}

function fakeMysqlClientCapturing(string $sandbox, string $outputFile): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $script = $sandbox.'/fake-mysql-capture.php';
        file_put_contents($script, '<?php file_put_contents('.var_export($outputFile, true).', stream_get_contents(STDIN));');
        $path = $sandbox.'/mysql-capture.cmd';
        file_put_contents($path, "@echo off\r\nphp \"{$script}\"\r\n");

        return $path;
    }
    $path = $sandbox.'/mysql-capture';
    file_put_contents($path, "#!/bin/sh\ncat > ".escapeshellarg($outputFile)."\nexit 0\n");
    chmod($path, 0700);

    return $path;
}

function fakeMysqlClientFailing(string $sandbox): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $path = $sandbox.'/mysql-failing.cmd';
        file_put_contents($path, "@echo off\r\nexit /b 1\r\n");

        return $path;
    }
    $path = $sandbox.'/mysql-failing';
    file_put_contents($path, "#!/bin/sh\ncat >/dev/null\nexit 1\n");
    chmod($path, 0700);

    return $path;
}

function gzipTarEntry(string $name, string $contents): string
{
    $field = static fn (string $value, int $length): string => str_pad(substr($value, 0, $length), $length, "\0");
    $octal = static fn (int $value, int $length): string => str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";
    $header = $field($name, 100)
        .$octal(0644, 8).$octal(0, 8).$octal(0, 8).$octal(strlen($contents), 12).$octal(time(), 12)
        .str_repeat(' ', 8).'0'.$field('', 100)."ustar\0".'00'.$field('nordipass', 32).$field('nordipass', 32)
        .$octal(0, 8).$octal(0, 8).$field('', 155).str_repeat("\0", 12);
    $checksum = array_sum(unpack('C*', $header));
    $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    $padding = (512 - (strlen($contents) % 512)) % 512;

    return gzencode($header.$contents.str_repeat("\0", $padding + 1024));
}

test('backup manifest covers every persistent business-data disk without recursive output', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    expect($manifest['version'])->toBe(2)
        ->and($manifest['scope'])->toBe(['database' => false, 'files' => true])
        ->and(array_column($manifest['artifacts']['files']['stores'], 'disk'))->toBe([
            'production_imports', 'product_documents', 'catalog_media', 'passport_assets',
        ]);

    $archiveBytes = Storage::disk('backup_test')->get("sets/{$id}/files.tar.gz");
    expect($archiveBytes)->not->toContain('nordipass/backups')
        ->and($archiveBytes)->not->toContain('backup-temp')
        ->and($archiveBytes)->not->toContain($id.'/files.tar.gz');
});

test('documents imports catalog media and passport assets survive backup delete and restore', function () {
    [$id] = createPersistentFilesBackup();
    foreach ($this->sourceRoots as $root) {
        removeBackupTestDirectory($root);
        mkdir($root, 0700, true);
        file_put_contents($root.'/stale.txt', 'must be replaced');
    }

    $exitCode = Artisan::call('nordipass:restore', ['backup-id' => $id, '--files-only' => true]);
    expect($exitCode)->toBe(0, Artisan::output())
        ->and(file_get_contents($this->sourceRoots['production_imports'].'/source.csv'))->toBe("sku,name\nA-1,Test\n")
        ->and(file_get_contents($this->sourceRoots['product_documents'].'/company/document/v1.pdf'))->toBe('%PDF-real-document-bytes')
        ->and(file_get_contents($this->sourceRoots['catalog_media'].'/company/product/image.png'))->toBe('real-catalog-image-bytes')
        ->and(file_get_contents($this->sourceRoots['passport_assets'].'/company/passport/version/media/asset.png'))->toBe('immutable-passport-asset-bytes')
        ->and(file_exists($this->sourceRoots['product_documents'].'/stale.txt'))->toBeFalse();
});

test('full restore runs database and persistent file restoration', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    addDatabaseArtifact($id, $manifest);
    Config::set('backup.database.restore_binary', fakeMysqlClient($this->backupSandbox));
    file_put_contents($this->sourceRoots['catalog_media'].'/company/product/image.png', 'damaged');

    $exitCode = Artisan::call('nordipass:restore', ['backup-id' => $id]);
    expect($exitCode)->toBe(0, Artisan::output())
        ->and(file_get_contents($this->sourceRoots['catalog_media'].'/company/product/image.png'))->toBe('real-catalog-image-bytes');
});

test('database restore recreates a dropped database before loading the dump', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    addDatabaseArtifact($id, $manifest);
    $captured = $this->backupSandbox.'/restore-stdin.sql';
    Config::set('backup.database.restore_binary', fakeMysqlClientCapturing($this->backupSandbox, $captured));

    $exitCode = Artisan::call('nordipass:restore', ['backup-id' => $id, '--database-only' => true]);
    expect($exitCode)->toBe(0, Artisan::output());

    $sql = file_get_contents($captured);
    $database = str_replace('`', '``', DB::connection()->getDatabaseName());
    expect($sql)->toContain('CREATE DATABASE IF NOT EXISTS `'.$database.'`')
        ->and($sql)->toContain('USE `'.$database.'`;')
        ->and($sql)->toContain('CREATE TABLE recovery_drill');
});

test('database restore failure aborts before replacing persistent files', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    addDatabaseArtifact($id, $manifest);
    Config::set('backup.database.restore_binary', fakeMysqlClientFailing($this->backupSandbox));
    file_put_contents($this->sourceRoots['catalog_media'].'/company/product/image.png', 'damaged');

    $exitCode = Artisan::call('nordipass:restore', ['backup-id' => $id]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Restore failed')->not->toContain('completed successfully')
        ->and(file_get_contents($this->sourceRoots['catalog_media'].'/company/product/image.png'))->toBe('damaged')
        ->and(file_get_contents($this->sourceRoots['product_documents'].'/company/document/v1.pdf'))->toBe('%PDF-real-document-bytes');
});

test('corrupted file archive fails restore before replacing existing files', function () {
    [$id] = createPersistentFilesBackup();
    Storage::disk('backup_test')->put("sets/{$id}/files.tar.gz", 'corrupt archive');
    $exitCode = Artisan::call('nordipass:restore', ['backup-id' => $id, '--files-only' => true]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->not->toContain('completed successfully')
        ->and(file_get_contents($this->sourceRoots['product_documents'].'/company/document/v1.pdf'))->toBe('%PDF-real-document-bytes');
});

test('missing file artifact fails restore', function () {
    [$id] = createPersistentFilesBackup();
    Storage::disk('backup_test')->delete("sets/{$id}/files.tar.gz");
    expect(Artisan::call('nordipass:restore', ['backup-id' => $id, '--files-only' => true]))->toBe(1);
    expect(Artisan::output())->toContain('artifact is missing')->not->toContain('completed successfully');
});

test('manifest checksum mismatch fails restore', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    $manifest['artifacts']['files']['sha256'] = str_repeat('0', 64);
    Storage::disk('backup_test')->put("sets/{$id}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(Artisan::call('nordipass:restore', ['backup-id' => $id, '--files-only' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Checksum or size mismatch')->not->toContain('completed successfully');
});

test('archive path traversal is rejected', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    $bytes = gzipTarEntry('stores/product_documents/../../outside.txt', 'escape');
    Storage::disk('backup_test')->put("sets/{$id}/files.tar.gz", $bytes);
    $manifest['artifacts']['files']['size'] = strlen($bytes);
    $manifest['artifacts']['files']['sha256'] = hash('sha256', $bytes);
    Storage::disk('backup_test')->put("sets/{$id}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(Artisan::call('nordipass:restore', ['backup-id' => $id, '--files-only' => true]))->toBe(1);
    expect(file_exists($this->backupSandbox.'/outside.txt'))->toBeFalse();
});

test('file activation failure cannot be reported as successful restore', function () {
    [$id] = createPersistentFilesBackup();
    $blocker = $this->backupSandbox.'/blocked-parent';
    file_put_contents($blocker, 'not a directory');
    Config::set('filesystems.disks.passport_assets.root', $blocker.'/passport-assets');
    Storage::forgetDisk('passport_assets');

    expect(Artisan::call('nordipass:restore', ['backup-id' => $id, '--files-only' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Restore failed')->not->toContain('completed successfully');
});

test('backup verifier rejects missing and checksum-mismatched artifacts', function () {
    [$id, $manifest] = createPersistentFilesBackup();
    Storage::disk('backup_test')->delete("sets/{$id}/files.tar.gz");
    expect(Artisan::call('nordipass:backup-verify', ['backup-id' => $id]))->toBe(4);

    Storage::disk('backup_test')->put("sets/{$id}/files.tar.gz", 'wrong');
    $manifest['artifacts']['files']['size'] = 5;
    Storage::disk('backup_test')->put("sets/{$id}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(Artisan::call('nordipass:backup-verify', ['backup-id' => $id]))->toBe(4);
});

test('backup rejects an output path nested inside a persistent source root', function () {
    Config::set('filesystems.disks.backup_test.root', $this->sourceRoots['product_documents'].'/backups');
    Storage::forgetDisk('backup_test');
    expect(Artisan::call('nordipass:backup', ['--files-only' => true]))->toBe(1);
    expect(Artisan::output())->toContain('overlaps persistent disk');
});
