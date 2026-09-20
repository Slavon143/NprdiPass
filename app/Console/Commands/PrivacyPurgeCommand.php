<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Webhooks\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrivacyPurgeCommand extends Command
{
    protected $signature = 'privacy:purge
        {--days= : Override the user erasure retention period}
        {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete erased user rows past retention and prune webhook delivery history';

    public function handle(): int
    {
        $userDays = $this->userRetentionDays();

        if ($userDays === null) {
            return self::INVALID;
        }

        $webhookDays = max(1, (int) config('privacy.webhook_delivery_retention_days', 90));

        $users = $this->purgedUsers($userDays);
        $deliveries = $this->purgedDeliveries($webhookDays);

        if ($this->option('dry-run')) {
            $this->info("{$users} erased user row(s) and {$deliveries} webhook delivery row(s) would be purged.");

            return self::SUCCESS;
        }

        $this->deleteUsers($userDays);
        $this->deleteDeliveries($webhookDays);

        $this->info("{$users} erased user row(s) and {$deliveries} webhook delivery row(s) purged.");

        return self::SUCCESS;
    }

    private function userRetentionDays(): ?int
    {
        $option = $this->option('days');

        if ($option === null) {
            return max(1, (int) config('privacy.user_erasure_retention_days', 30));
        }

        if (! ctype_digit($option) || (int) $option < 1) {
            $this->error('The --days option must be a positive integer.');

            return null;
        }

        return (int) $option;
    }

    private function purgedUsers(int $days): int
    {
        return User::query()
            ->onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->count();
    }

    private function purgedDeliveries(int $days): int
    {
        return WebhookDelivery::query()
            ->where('created_at', '<', now()->subDays($days))
            ->count();
    }

    private function deleteUsers(int $days): void
    {
        do {
            $ids = User::query()
                ->onlyTrashed()
                ->where('deleted_at', '<', now()->subDays($days))
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            // Detach Spatie permission/role morph rows (no FK constraint on model_id).
            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->whereIn('model_id', $ids->all())
                ->delete();
            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $ids->all())
                ->delete();

            User::query()->onlyTrashed()->whereKey($ids->all())->forceDelete();
        } while (true);
    }

    private function deleteDeliveries(int $days): void
    {
        do {
            $ids = WebhookDelivery::query()
                ->where('created_at', '<', now()->subDays($days))
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            WebhookDelivery::query()->whereKey($ids->all())->delete();
        } while (true);
    }
}
