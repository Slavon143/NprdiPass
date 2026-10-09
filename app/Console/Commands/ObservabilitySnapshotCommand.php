<?php

namespace App\Console\Commands;

use App\Services\Observability\ObservabilitySnapshotService;
use Illuminate\Console\Command;

class ObservabilitySnapshotCommand extends Command
{
    protected $signature = 'observability:snapshot {--json : Emit machine-readable JSON}';

    protected $description = 'Capture the R3.17 performance and observability snapshot';

    public function handle(ObservabilitySnapshotService $service): int
    {
        $snapshot = $service->snapshot();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Observability status: '.$snapshot['status']);
        $this->table(
            ['Check', 'Status'],
            collect($snapshot['checks'])->map(fn (string $status, string $check): array => [$check, $status])->values()->all(),
        );

        return self::SUCCESS;
    }
}
