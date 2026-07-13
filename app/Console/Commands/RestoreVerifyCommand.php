<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreVerifyCommand extends Command
{
    protected $signature = 'nordipass:restore-verify
        {--connection= : Database connection to verify (default: current connection)}';

    protected $description = 'Verify that the restored database is consistent';

    public function handle(): int
    {
        $connection = $this->option('connection') ?? config('database.default');

        $this->line('Running restore verification on connection: '.$connection.'...');

        $checks = [
            'database_connection' => $this->checkDatabaseConnection($connection),
            'migrations_table' => $this->checkMigrationsTable($connection),
            'companies_table' => $this->checkTableExists('companies', $connection),
            'users_table' => $this->checkTableExists('users', $connection),
            'company_user_table' => $this->checkTableExists('company_user', $connection),
            'company_invitations_table' => $this->checkTableExists('company_invitations', $connection),
            'companies_have_records' => $this->checkTableHasRecords('companies', $connection),
            'users_have_records' => $this->checkTableHasRecords('users', $connection),
            'company_user_references' => $this->checkForeignKeyReferences('company_user', 'company_id', 'companies', $connection),
            'invitation_references' => $this->checkForeignKeyReferences('company_invitations', 'company_id', 'companies', $connection),
        ];

        $allOk = true;

        foreach ($checks as $name => $result) {
            $status = $result ? 'OK' : 'FAIL';
            $this->line("  {$name}: {$status}");

            if (! $result) {
                $allOk = false;
            }
        }

        if ($allOk) {
            $this->info('Restore verification passed.');

            return 0;
        }

        $this->error('Restore verification found issues.');

        return 1;
    }

    private function checkDatabaseConnection(string $connection): bool
    {
        try {
            DB::connection($connection)->select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkMigrationsTable(string $connection): bool
    {
        try {
            DB::connection($connection)->select('SELECT 1 FROM migrations LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkTableExists(string $table, string $connection): bool
    {
        try {
            DB::connection($connection)->select("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkTableHasRecords(string $table, string $connection): bool
    {
        try {
            return DB::connection($connection)->table($table)->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkForeignKeyReferences(string $childTable, string $foreignKey, string $parentTable, string $connection): bool
    {
        try {
            $db = DB::connection($connection);

            $orphans = $db->table($childTable)
                ->leftJoin($parentTable, "{$childTable}.{$foreignKey}", '=', "{$parentTable}.id")
                ->whereNull("{$parentTable}.id")
                ->count();

            return $orphans === 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
