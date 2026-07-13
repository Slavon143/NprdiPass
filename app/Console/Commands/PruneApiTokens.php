<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneApiTokens extends Command
{
    protected $signature = 'nordipass:prune-api-tokens
        {--dry-run : Report matching tokens without deleting them}
        {--days= : Retain invalid token rows for this many days}';

    protected $description = 'Prune expired and orphaned company API tokens';

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return self::FAILURE;
        }

        $query = $this->prunableQuery($days);
        $count = (clone $query)->count();

        if ((bool) $this->option('dry-run')) {
            $this->info("{$count} API token(s) would be pruned.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $query->select('personal_access_tokens.id')->chunkById(500, function ($tokens) use (&$deleted): void {
            $ids = $tokens->modelKeys();
            $deleted += PersonalAccessToken::query()->whereKey($ids)->delete();
        });

        $this->info("{$deleted} API token(s) pruned.");

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $value = $this->option('days');
        $days = $value === null
            ? (int) config('api.token_retention_days', 30)
            : filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 0 || $days > 3650) {
            $this->error('The --days option must be an integer between 0 and 3650.');

            return null;
        }

        return $days;
    }

    /** @return Builder<PersonalAccessToken> */
    private function prunableQuery(int $days): Builder
    {
        $cutoff = now()->subDays($days);

        return PersonalAccessToken::query()->where(function (Builder $query) use ($cutoff): void {
            $query->where('expires_at', '<=', $cutoff)
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->whereNull('company_id')->where('created_at', '<=', $cutoff);
                })
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->whereNotNull('company_id')
                        ->whereNotIn('company_id', Company::query()->select('id'))
                        ->where('created_at', '<=', $cutoff);
                });

            if (config('api.prune_inactive_user_tokens', true)) {
                $query->orWhere(function (Builder $query) use ($cutoff): void {
                    $inactiveUsers = User::withTrashed()
                        ->where(function (Builder $query): void {
                            $query->where('status', '!=', UserStatus::Active->value)
                                ->orWhereNotNull('deleted_at');
                        })
                        ->select('id');

                    $query->where('tokenable_type', User::class)
                        ->whereIn('tokenable_id', $inactiveUsers)
                        ->where('created_at', '<=', $cutoff);
                });
            }
        });
    }
}
