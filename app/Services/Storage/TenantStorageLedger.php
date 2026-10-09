<?php

namespace App\Services\Storage;

use App\Data\Storage\StorageReservation;
use App\Models\Company;
use App\Services\Billing\CommercialAccessPolicy;
use App\Services\Billing\EntitlementService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class TenantStorageLedger
{
    public function __construct(private readonly TenantStorageInventory $inventory) {}

    public function reserve(Company $company, string $disk, string $key, int $bytes, string $operationUuid): StorageReservation
    {
        if ($bytes < 0 || ! Str::isUuid($operationUuid)) {
            throw new LogicException('Invalid storage reservation.');
        }
        // Remote uncertain writes need provider-specific fencing/reconciliation.
        // The first admission implementation supports the application's local disks.
        if (config('filesystems.disks.'.$disk.'.driver') !== 'local') {
            throw ValidationException::withMessages(['file' => 'Storage quota admission is not configured for this backend.']);
        }
        $reservation = null;
        try {
            return DB::transaction(function () use ($company, $disk, $key, $bytes, $operationUuid, &$reservation): StorageReservation {
                $current = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
                app(CommercialAccessPolicy::class)->assertWritable($current);
                $limit = $current->commercial_model_version === 1
                    ? app(EntitlementService::class)->limitForMutation($current, 'storage.bytes.max') : null;
                if ($current->commercial_model_version === 1 && ($limit === null || $limit < 0)) {
                    throw ValidationException::withMessages(['file' => 'The storage allowance is unavailable.']);
                }
                $physicalKey = $this->inventory->physicalKey($current, $disk, $key);
                $ledger = $this->connection();
                // Keep the initial inventory even when the subsequent admission
                // is refused; repeated over-quota requests must not rescan files.
                $ledger->transaction(fn () => $this->initialize($ledger, $current));

                return $ledger->transaction(function () use ($ledger, $current, $disk, $key, $bytes, $operationUuid, $physicalKey, $limit, &$reservation): StorageReservation {
                    $balance = $ledger->table('commercial_storage_balances')->where('company_uuid', $current->uuid)->lockForUpdate()->firstOrFail();
                    // Insert before the locking read: absent-key gap locks must
                    // not serialize unrelated tenants or race duplicate claims.
                    $inserted = $ledger->table('commercial_storage_objects')->insertOrIgnore([
                        'company_uuid' => $current->uuid, 'physical_key' => $physicalKey, 'disk' => $disk,
                        'storage_key' => $key, 'operation_uuid' => $operationUuid, 'state' => 'reserved',
                        'reserved_bytes' => $bytes, 'charged_bytes' => $bytes, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $object = $ledger->table('commercial_storage_objects')->where('company_uuid', $current->uuid)
                        ->where('physical_key', $physicalKey)->lockForUpdate()->firstOrFail();
                    if ($object->operation_uuid !== $operationUuid || (int) $object->reserved_bytes !== $bytes || $object->state === 'released') {
                        throw ValidationException::withMessages(['file' => 'This physical storage key belongs to another or completed operation.']);
                    }
                    if ($inserted !== 0) {
                        $used = (int) $balance->used_bytes;
                        if ($used > PHP_INT_MAX - $bytes || ($limit !== null && ($used > $limit || $bytes > $limit - $used))) {
                            throw ValidationException::withMessages(['file' => 'The storage allowance has been reached.']);
                        }
                        $ledger->table('commercial_storage_balances')->where('company_uuid', $current->uuid)
                            ->update(['used_bytes' => $used + $bytes, 'updated_at' => now()]);
                    }
                    $reservation = new StorageReservation($current->uuid, $disk, $key, $physicalKey, $operationUuid, $bytes);

                    return $reservation;
                });
            });
        } catch (Throwable $exception) {
            // No file write has begun inside reserve(). Retire a known local
            // claim on definite absence; an unavailable ledger stays conservative.
            if ($reservation instanceof StorageReservation) {
                try {
                    $this->releaseConfirmed($company, $reservation);
                } catch (Throwable) {
                    Log::warning('Storage reservation requires reconciliation.', ['company_uuid' => $reservation->companyUuid,
                        'operation_uuid' => $operationUuid, 'error_code' => 'reservation_ack_uncertain']);
                }
            }
            throw $exception;
        }
    }

    public function markWritten(Company $company, StorageReservation $reservation): void
    {
        $this->assertBinding($company, $reservation);
        $actual = Storage::disk($reservation->disk)->size($reservation->key);
        if ($actual < 0) {
            throw new LogicException('The written storage size is invalid.');
        }
        $ledger = $this->connection();
        $ledger->transaction(function () use ($ledger, $reservation, $actual): void {
            $balance = $ledger->table('commercial_storage_balances')->where('company_uuid', $reservation->companyUuid)->lockForUpdate()->firstOrFail();
            $object = $this->lockedObject($ledger, $reservation);
            if ($object->state === 'released' || ($object->state === 'present' && (int) $object->charged_bytes !== $actual)) {
                throw new LogicException('Storage write state changed unexpectedly.');
            }
            $change = $actual - (int) $object->charged_bytes;
            $used = (int) $balance->used_bytes;
            if (($change > 0 && $used > PHP_INT_MAX - $change) || ($change < 0 && $used < -$change)) {
                throw new LogicException('Storage accounting requires reconciliation.');
            }
            $ledger->table('commercial_storage_objects')->where('id', $object->id)->update(['state' => 'present', 'charged_bytes' => $actual, 'updated_at' => now()]);
            $ledger->table('commercial_storage_balances')->where('company_uuid', $reservation->companyUuid)
                ->update(['used_bytes' => $used + $change, 'updated_at' => now()]);
        });
        // Even a failed oversized write owns physical bytes until compensation
        // confirms removal. Charge their observed size before refusing the write.
        if ($actual > $reservation->bytes) {
            throw new LogicException('Written bytes exceed their storage reservation.');
        }
    }

    public function releaseConfirmed(Company $company, StorageReservation $reservation): void
    {
        $this->assertBinding($company, $reservation);
        if (Storage::disk($reservation->disk)->exists($reservation->key)) {
            throw new LogicException('Physical bytes still exist; storage cannot be released.');
        }
        $ledger = $this->connection();
        $ledger->transaction(function () use ($ledger, $reservation): void {
            $balance = $ledger->table('commercial_storage_balances')->where('company_uuid', $reservation->companyUuid)->lockForUpdate()->firstOrFail();
            $object = $this->lockedObject($ledger, $reservation);
            if ($object->state === 'released') {
                return;
            }
            if ((int) $balance->used_bytes < (int) $object->charged_bytes) {
                throw new LogicException('Storage accounting requires reconciliation.');
            }
            $ledger->table('commercial_storage_balances')->where('company_uuid', $reservation->companyUuid)
                ->update(['used_bytes' => (int) $balance->used_bytes - (int) $object->charged_bytes, 'updated_at' => now()]);
            $ledger->table('commercial_storage_objects')->where('id', $object->id)->update(['state' => 'released', 'charged_bytes' => 0, 'updated_at' => now()]);
        });
    }

    public function usedBytes(Company $company): ?int
    {
        $used = $this->connection()->table('commercial_storage_balances')->where('company_uuid', $company->uuid)
            ->whereNotNull('initialized_at')->value('used_bytes');

        return $used === null ? null : (int) $used;
    }

    public function hasUnresolvedWrite(Company $company, string $disk, string $key, bool $allowPresentOperation = false): bool
    {
        $physicalKey = $this->inventory->physicalKey($company, $disk, $key);
        $object = $this->connection()->table('commercial_storage_objects')
            ->where('company_uuid', $company->uuid)->where('physical_key', $physicalKey)->first();

        return $object !== null && ($object->state === 'reserved'
            || (! $allowPresentOperation && $object->state !== 'released' && $object->operation_uuid !== null));
    }

    public function releaseRemovedKey(Company $company, string $disk, string $key): void
    {
        $ledger = $this->connection();
        // Uninitialized legacy storage has no charge to release. Its next
        // inventory will naturally omit the confirmed deletion.
        if (! $ledger->table('commercial_storage_balances')->where('company_uuid', $company->uuid)->exists()) {
            return;
        }
        $physicalKey = $this->inventory->physicalKey($company, $disk, $key);
        if (Storage::disk($disk)->exists($key)) {
            throw new LogicException('Physical bytes still exist; storage cannot be released.');
        }
        $ledger->transaction(function () use ($ledger, $company, $physicalKey): void {
            $balance = $ledger->table('commercial_storage_balances')->where('company_uuid', $company->uuid)->lockForUpdate()->firstOrFail();
            $object = $ledger->table('commercial_storage_objects')->where('company_uuid', $company->uuid)
                ->where('physical_key', $physicalKey)->lockForUpdate()->first();
            if ($object === null || $object->state === 'released') {
                return;
            }
            if ($object->state !== 'present' || (int) $balance->used_bytes < (int) $object->charged_bytes) {
                throw new LogicException('In-flight or inconsistent storage requires operation-specific reconciliation.');
            }
            $ledger->table('commercial_storage_balances')->where('company_uuid', $company->uuid)
                ->update(['used_bytes' => (int) $balance->used_bytes - (int) $object->charged_bytes, 'updated_at' => now()]);
            $ledger->table('commercial_storage_objects')->where('id', $object->id)
                ->update(['state' => 'released', 'charged_bytes' => 0, 'updated_at' => now()]);
        });
    }

    private function assertBinding(Company $company, StorageReservation $reservation): void
    {
        if ($company->uuid !== $reservation->companyUuid || config('filesystems.disks.'.$reservation->disk.'.driver') !== 'local'
            || $this->inventory->physicalKey($company, $reservation->disk, $reservation->key) !== $reservation->physicalKey) {
            throw new LogicException('Storage reservation binding changed.');
        }
    }

    private function lockedObject(Connection $ledger, StorageReservation $reservation): object
    {
        $object = $ledger->table('commercial_storage_objects')->where('company_uuid', $reservation->companyUuid)
            ->where('physical_key', $reservation->physicalKey)->lockForUpdate()->firstOrFail();
        if ($object->operation_uuid !== $reservation->operationUuid || (int) $object->reserved_bytes !== $reservation->bytes) {
            throw new LogicException('Storage reservation identity does not match.');
        }

        return $object;
    }

    private function initialize(Connection $ledger, Company $company): void
    {
        $ledger->table('commercial_storage_balances')->insertOrIgnore(['company_uuid' => $company->uuid,
            'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $balance = $ledger->table('commercial_storage_balances')->where('company_uuid', $company->uuid)->lockForUpdate()->firstOrFail();
        if ($balance->initialized_at !== null) {
            return;
        }
        if ($ledger->table('commercial_storage_objects')->where('company_uuid', $company->uuid)->exists()) {
            throw new LogicException('Uninitialized storage has existing accounting records.');
        }
        $bytes = 0;
        $batch = [];
        foreach ($this->inventory->objects($company) as $object) {
            if ($bytes > PHP_INT_MAX - $object['bytes']) {
                throw new LogicException('Storage inventory exceeds the supported size.');
            }
            $bytes += $object['bytes'];
            $batch[] = ['company_uuid' => $company->uuid, 'physical_key' => $object['physical_key'], 'disk' => $object['disk'],
                'storage_key' => $object['key'], 'operation_uuid' => null, 'state' => 'present',
                'reserved_bytes' => $object['bytes'], 'charged_bytes' => $object['bytes'], 'created_at' => now(), 'updated_at' => now()];
            if (count($batch) === 500) {
                $ledger->table('commercial_storage_objects')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $ledger->table('commercial_storage_objects')->insert($batch);
        }
        $ledger->table('commercial_storage_balances')->where('company_uuid', $company->uuid)
            ->update(['used_bytes' => $bytes, 'initialized_at' => now(), 'updated_at' => now()]);
    }

    private function connection(): Connection
    {
        $config = DB::connection()->getConfig();
        $config = array_merge($config, $config['write'] ?? []);
        unset($config['read'], $config['write'], $config['url'], $config['name']);
        $name = 'commercial_storage_'.substr(hash('sha256', serialize($config)), 0, 16);
        config(['database.connections.'.$name => $config]);

        return DB::connection($name);
    }
}
