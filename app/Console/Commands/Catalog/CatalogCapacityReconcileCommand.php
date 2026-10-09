<?php

namespace App\Console\Commands\Catalog;

use App\Models\Company;
use App\Services\Catalog\CatalogCapacityReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CatalogCapacityReconcileCommand extends Command
{
    protected $signature = 'catalog:capacity-reconcile
        {--company= : Company UUID}
        {--all-companies : Check every company}
        {--repair : Initialize or repair counters; otherwise report drift only}';

    protected $description = 'Reconcile catalog slot counters under the company mutex';

    public function handle(CatalogCapacityReconciler $reconciler): int
    {
        $uuid = $this->option('company');
        $all = (bool) $this->option('all-companies');
        if (($uuid === null && ! $all) || ($uuid !== null && $all) || ($uuid !== null && ! Str::isUuid($uuid))) {
            $this->error('Specify either a valid --company UUID or --all-companies.');

            return 2;
        }

        $query = Company::withTrashed();
        if ($uuid !== null) {
            $query->where('uuid', $uuid);
            if (! $query->exists()) {
                $this->error('Company not found.');

                return 3;
            }
        }
        $repair = (bool) $this->option('repair');
        $drift = false;
        $query->select('id')->chunkById(100, function ($companies) use ($reconciler, $repair, &$drift): void {
            foreach ($companies as $company) {
                $result = $reconciler->reconcile((int) $company->getKey(), $repair);
                $drift = $drift || $result['previous_count'] !== $result['actual_count']
                    || $result['previous_total'] !== $result['actual_total'];
                $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));
            }
        });

        return $drift && ! $repair ? 1 : 0;
    }
}
