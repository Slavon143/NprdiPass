<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PruneAuditLogs extends Command
{
    protected $signature = 'nordipass:prune-audit-logs
        {--days= : Override the configured retention period}
        {--dry-run : Count records without deleting them}
        {--company= : Limit pruning to one company UUID}';

    protected $description = 'Prune tenant audit history older than the retention period';

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return self::INVALID;
        }

        $query = AuditLog::query()
            ->where('log_name', 'tenant')
            ->where('created_at', '<', now()->subDays($days));
        $companyUuid = $this->option('company');

        if (is_string($companyUuid) && $companyUuid !== '') {
            if (! Str::isUuid($companyUuid)) {
                $this->error('The --company option must be a valid company UUID.');

                return self::INVALID;
            }

            $company = Company::withTrashed()->where('uuid', $companyUuid)->first();

            if ($company === null) {
                $this->error('The requested company UUID was not found.');

                return self::FAILURE;
            }

            $query->where('company_id', $company->getKey());
        }

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} audit log record(s) would be pruned.");

            return self::SUCCESS;
        }

        $this->deleteInChunks($query);
        $this->info("{$count} audit log record(s) pruned.");

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $option = $this->option('days');

        if ($option === null) {
            return max(1, (int) config('audit.retention_days', 365));
        }

        if (! ctype_digit($option) || (int) $option < 1) {
            $this->error('The --days option must be a positive integer.');

            return null;
        }

        return (int) $option;
    }

    /** @param Builder<AuditLog> $query */
    private function deleteInChunks(Builder $query): void
    {
        do {
            $ids = (clone $query)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            AuditLog::query()->whereKey($ids)->delete();
        } while (true);
    }
}
