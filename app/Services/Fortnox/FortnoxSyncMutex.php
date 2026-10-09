<?php

namespace App\Services\Fortnox;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;

class FortnoxSyncMutex
{
    private ?PDO $owner = null;

    public function assertHeld(): void
    {
        if ($this->owner === null || DB::connection()->getPdo() !== $this->owner) {
            throw new RuntimeException('Fortnox sync lost its database connection lock.');
        }
    }

    public function run(Company $company, callable $operation): mixed
    {
        if ($this->owner !== null) {
            throw ValidationException::withMessages(['sync' => 'A Fortnox sync is already in progress for this connection.']);
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $key = hash('sha256', $connection->getDatabaseName().'|fortnox-sync|'.$company->getKey());
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$key]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw ValidationException::withMessages(['sync' => 'A Fortnox sync is already in progress for this connection.']);
        }
        $this->owner = $pdo;
        try {
            return $operation();
        } finally {
            $this->owner = null;
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$key]);
        }
    }
}
