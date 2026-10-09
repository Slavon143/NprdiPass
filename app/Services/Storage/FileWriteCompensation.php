<?php

namespace App\Services\Storage;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FileWriteCompensation
{
    /**
     * @param  Closure(): bool  $isReferenced  Current, tenant-scoped ownership read.
     * @param  Closure(): bool  $delete  Physical deletion acknowledgement.
     * @param  array<string, int|string>  $context  Scalar non-secret operation identifiers.
     * @return Closure(): void
     */
    public function track(Closure $isReferenced, Closure $delete, array $context): Closure
    {
        $cleaned = false;
        $cleanup = static function () use ($isReferenced, $delete, $context, &$cleaned): void {
            if ($cleaned) {
                return;
            }
            try {
                // A post-commit exception is not a rollback. If database state
                // cannot be established, retain the bytes for reconciliation.
                if ($isReferenced()) {
                    return;
                }
                if (! $delete()) {
                    Log::warning('File write compensation was not confirmed.', $context + ['error_code' => 'delete_not_confirmed']);
                } else {
                    $cleaned = true;
                }
            } catch (Throwable) {
                Log::warning('File write compensation requires reconciliation.', $context + ['error_code' => 'compensation_uncertain']);
            }
        };
        $connection = DB::connection();
        // Committed child records are discarded by Laravel on ancestor
        // rollback. Register directly with every active ancestor instead.
        foreach (app('db.transactions')->getPendingTransactions()->where('connection', $connection->getName()) as $transaction) {
            $transaction->addCallbackForRollback($cleanup);
        }

        return $cleanup;
    }
}
