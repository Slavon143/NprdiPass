<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreVerifyCommand extends Command
{
    protected $signature = 'nordipass:restore-verify';

    protected $description = 'Verify that the restored database is consistent';

    public function handle(): int
    {
        $this->line('Running restore verification...');

        $checks = [
            'database_connection' => $this->checkDatabaseConnection(),
            'migrations_table' => $this->checkMigrationsTable(),
            'companies_table' => $this->checkTableExists('companies'),
            'users_table' => $this->checkTableExists('users'),
            'company_user_table' => $this->checkTableExists('company_user'),
            'company_invitations_table' => $this->checkTableExists('company_invitations'),
            'companies_have_records' => $this->checkTableHasRecords('companies'),
            'users_have_records' => $this->checkTableHasRecords('users'),
            'company_user_references' => $this->checkForeignKeyReferences('company_user', 'company_id', 'companies'),
            'invitation_references' => $this->checkForeignKeyReferences('company_invitations', 'company_id', 'companies'),
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

    private function checkDatabaseConnection(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkMigrationsTable(): bool
    {
        try {
            DB::select('SELECT 1 FROM migrations LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkTableExists(string $table): bool
    {
        try {
            DB::select("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkTableHasRecords(string $table): bool
    {
        try {
            return DB::table($table)->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkForeignKeyReferences(string $childTable, string $foreignKey, string $parentTable): bool
    {
        try {
            $orphans = DB::table($childTable)
                ->leftJoin($parentTable, "{$childTable}.{$foreignKey}", '=', "{$parentTable}.id")
                ->whereNull("{$parentTable}.id")
                ->count();

            return $orphans === 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
