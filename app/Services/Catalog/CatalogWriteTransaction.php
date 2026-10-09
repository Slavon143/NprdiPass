<?php

namespace App\Services\Catalog;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Throwable;

/** Product/assignment locks protect writes; unrelated missing assignments need no gap mutex. */
class CatalogWriteTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback, int $attempts = 1): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0 || $connection->getDriverName() !== 'mysql') {
            // A caller owns its outer transaction and its isolation contract.
            return $connection->transaction($callback, $attempts);
        }
        try {
            $previous = (string) $connection->scalar('SELECT @@SESSION.transaction_isolation', [], false);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1193) {
                throw $exception;
            }
            // MariaDB versions retaining the older variable name.
            $previous = (string) $connection->scalar('SELECT @@SESSION.tx_isolation', [], false);
        }
        $levels = ['READ-UNCOMMITTED' => 'READ UNCOMMITTED', 'READ-COMMITTED' => 'READ COMMITTED',
            'REPEATABLE-READ' => 'REPEATABLE READ', 'SERIALIZABLE' => 'SERIALIZABLE'];
        if (! isset($levels[$previous])) {
            throw new LogicException('Unsupported catalogue transaction isolation.');
        }
        if ($previous === 'READ-COMMITTED') {
            return $connection->transaction($callback, $attempts);
        }
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $failure = null;
        try {
            // All native deadlock retries keep this isolation until the call ends.
            return $connection->transaction($callback, $attempts);
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $pdo = $connection->getRawPdo();
                if ($pdo instanceof PDO) {
                    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$levels[$previous]);
                }
            } catch (Throwable $resetFailure) {
                // Discard changed session state. Never hide a failed/uncertain commit
                // behind a reconnect or a different exception used by retry logic.
                DB::purge($connection->getName());
                if ($failure === null) {
                    throw $resetFailure;
                }
            }
        }
    }
}
