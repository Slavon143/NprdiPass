<?php

namespace App\Console\Commands;

use App\Actions\Billing\ReconcileBillingProjectionAction;
use Illuminate\Console\Command;
use Throwable;

class BillingReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Reconcile local billing projections against Stripe.';

    public function handle(ReconcileBillingProjectionAction $action): int
    {
        if (! (bool) config('features.billing')) {
            $this->components->warn('Billing feature is disabled.');

            return self::SUCCESS;
        }

        try {
            $summary = $action->execute();
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Billing reconciliation complete: checked=%d updated=%d skipped=%d',
            $summary['checked'],
            $summary['updated'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }
}
