<?php

namespace App\Console\Commands;

use App\Models\CompanyInvitation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneInvitations extends Command
{
    protected $signature = 'nordipass:prune-invitations {--dry-run : Count records without deleting them}';

    protected $description = 'Prune old accepted, cancelled, and expired company invitation history';

    public function handle(): int
    {
        $retentionDays = max(1, (int) config('invitations.retention_days', 180));
        $cutoff = now()->subDays($retentionDays);
        $query = $this->prunableQuery($cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} invitation record(s) would be pruned.");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("{$count} invitation record(s) pruned.");

        return self::SUCCESS;
    }

    private function prunableQuery(\DateTimeInterface $cutoff): Builder
    {
        return CompanyInvitation::query()->where(function (Builder $query) use ($cutoff): void {
            $query->where(function (Builder $accepted) use ($cutoff): void {
                $accepted->whereNotNull('accepted_at')
                    ->where('accepted_at', '<', $cutoff);
            })->orWhere(function (Builder $cancelled) use ($cutoff): void {
                $cancelled->whereNotNull('cancelled_at')
                    ->where('cancelled_at', '<', $cutoff);
            })->orWhere(function (Builder $expired) use ($cutoff): void {
                $expired->whereNull('accepted_at')
                    ->whereNull('cancelled_at')
                    ->where('expires_at', '<', $cutoff);
            });
        });
    }
}
