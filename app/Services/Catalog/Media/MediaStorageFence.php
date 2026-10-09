<?php

namespace App\Services\Catalog\Media;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MediaStorageFence
{
    public function writer(string $disk): ?MediaStorageLease
    {
        if (config('filesystems.disks.'.$disk.'.driver') !== 'local') {
            return null;
        }
        $lease = $this->acquire($disk, LOCK_SH);
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            $transactions = app('db.transactions')->getPendingTransactions()->where('connection', $connection->getName());
            // Register on the actual outer record: the testing transaction
            // manager deliberately executes facade afterCommit callbacks early.
            $transactions->sortBy('level')->first()->addCallback(fn () => $lease->release());
            foreach ($transactions as $transaction) {
                $transaction->addCallbackForRollback(fn () => $lease->release());
            }
        }

        return $lease;
    }

    public function cleanup(string $disk): MediaStorageLease
    {
        if (config('filesystems.disks.'.$disk.'.driver') !== 'local') {
            throw new RuntimeException('Media cleanup requires a local storage fence.');
        }

        return $this->acquire($disk, LOCK_EX);
    }

    public function lockPath(string $disk): string
    {
        $root = Storage::disk($disk)->path('');
        if (! is_dir($root) && ! mkdir($root, 0770, true) && ! is_dir($root)) {
            throw new RuntimeException('Media storage fence is unavailable.');
        }
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Media storage fence is unavailable.');
        }
        $identity = PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $root)) : $root;
        $directory = dirname($root).'/.nordipass-media-locks';
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory))) {
            throw new RuntimeException('Media storage fence is unavailable.');
        }
        $path = $directory.'/'.hash('sha256', $identity).'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Media storage fence is unavailable.');
        }

        return $path;
    }

    private function acquire(string $disk, int $mode): MediaStorageLease
    {
        // The control file lives outside the media root and tenant inventories.
        // Aliases resolving to the same physical root share its identity.
        $handle = fopen($this->lockPath($disk), 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Media storage fence is unavailable.');
        }
        if (! flock($handle, $mode | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Media storage is busy; retry after active operations complete.');
        }

        return new MediaStorageLease($handle);
    }
}
