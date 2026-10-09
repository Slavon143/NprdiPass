<?php

namespace App\Services\Storage;

use App\Data\Storage\StorageReservation;
use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\DB;

class StorageQuotaAdmission
{
    public function __construct(private readonly TenantStorageLedger $ledger) {}

    public function reserve(Company $company, string $disk, string $key, int $bytes, string $operationUuid): ?StorageReservation
    {
        return DB::transaction(function () use ($company, $disk, $key, $bytes, $operationUuid): ?StorageReservation {
            $current = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();

            return $current->commercial_model_version === 1
                ? $this->ledger->reserve($current, $disk, $key, $bytes, $operationUuid) : null;
        });
    }

    public function written(Company $company, ?StorageReservation $reservation): void
    {
        if ($reservation !== null) {
            $this->ledger->markWritten($company, $reservation);
        }
    }

    /** @param Closure(): bool $delete */
    public function compensate(Company $company, ?StorageReservation $reservation, Closure $delete): bool
    {
        if (! $delete()) {
            return false;
        }
        if ($reservation !== null) {
            $this->ledger->releaseConfirmed($company, $reservation);
        }

        return true;
    }
}
