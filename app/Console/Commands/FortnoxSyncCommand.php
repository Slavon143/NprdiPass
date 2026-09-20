<?php

namespace App\Console\Commands;

use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FortnoxSyncCommand extends Command
{
    protected $signature = 'fortnox:sync
        {--company= : Company UUID to sync}
        {--all-companies : Sync all active Fortnox connections}
        {--limit=50 : Maximum number of connections to process}
        {--format=table : Output format (table or json)}';

    protected $description = 'Run scheduled Fortnox article synchronization for one or more companies';

    public function handle(
        SyncFortnoxArticlesAction $sync,
        CurrentCompany $currentCompany,
        TokenCurrentCompany $tokenCurrentCompany,
    ): int {
        if (! (bool) config('features.fortnox_integration')) {
            $this->info('Fortnox integration is disabled; scheduled sync skipped.');

            return self::SUCCESS;
        }

        $companyUuid = (string) ($this->option('company') ?? '');
        $allCompanies = (bool) $this->option('all-companies');

        if ($companyUuid === '' && ! $allCompanies) {
            $this->error('You must specify either --company or --all-companies.');

            return self::INVALID;
        }

        if ($companyUuid !== '' && $allCompanies) {
            $this->error('You cannot specify both --company and --all-companies.');

            return self::INVALID;
        }

        $format = (string) ($this->option('format') ?? 'table');
        if (! in_array($format, ['table', 'json'], true)) {
            $format = 'table';
        }

        $connections = $this->connections($companyUuid);
        if ($connections === null) {
            return self::INVALID;
        }

        if ($connections->isEmpty()) {
            $this->info('No active Fortnox connections found.');

            return self::SUCCESS;
        }

        $results = [];
        $failures = 0;

        foreach ($connections as $connection) {
            $company = $connection->company;
            $actor = $connection->connectedBy;

            if ($actor === null) {
                $results[] = [
                    'company' => $company->name,
                    'company_uuid' => $company->uuid,
                    'status' => 'skipped',
                    'run_uuid' => null,
                    'articles_fetched' => 0,
                    'articles_created' => 0,
                    'articles_updated' => 0,
                    'articles_skipped' => 0,
                    'conflicts_raised' => 0,
                    'message' => 'The Fortnox connection has no connected user.',
                ];

                continue;
            }

            $currentCompany->clear();
            $tokenCurrentCompany->set($company);

            try {
                $run = $sync->execute($actor, $company, FortnoxSyncTrigger::Scheduled);

                $results[] = [
                    'company' => $company->name,
                    'company_uuid' => $company->uuid,
                    'status' => $run->status->value,
                    'run_uuid' => $run->uuid,
                    'articles_fetched' => $run->articles_fetched,
                    'articles_created' => $run->articles_created,
                    'articles_updated' => $run->articles_updated,
                    'articles_skipped' => $run->articles_skipped,
                    'conflicts_raised' => $run->conflicts_raised,
                    'message' => null,
                ];
            } catch (ValidationException $exception) {
                $results[] = [
                    'company' => $company->name,
                    'company_uuid' => $company->uuid,
                    'status' => 'skipped',
                    'run_uuid' => null,
                    'articles_fetched' => 0,
                    'articles_created' => 0,
                    'articles_updated' => 0,
                    'articles_skipped' => 0,
                    'conflicts_raised' => 0,
                    'message' => $exception->getMessage(),
                ];
            } catch (Throwable $exception) {
                $failures++;
                $results[] = [
                    'company' => $company->name,
                    'company_uuid' => $company->uuid,
                    'status' => 'failed',
                    'run_uuid' => null,
                    'articles_fetched' => 0,
                    'articles_created' => 0,
                    'articles_updated' => 0,
                    'articles_skipped' => 0,
                    'conflicts_raised' => 0,
                    'message' => $exception->getMessage(),
                ];
            } finally {
                $tokenCurrentCompany->clear();
                $currentCompany->clear();
            }
        }

        $this->renderResults($results, $format);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, FortnoxConnection>|null */
    private function connections(string $companyUuid): ?Collection
    {
        $query = FortnoxConnection::query()
            ->with(['company', 'connectedBy'])
            ->where('status', FortnoxConnectionStatus::Active->value)
            ->orderBy('id');

        if ($companyUuid !== '') {
            if (! Str::isUuid($companyUuid)) {
                $this->error('The --company option must be a valid company UUID.');

                return null;
            }

            $company = Company::query()->where('uuid', $companyUuid)->first();
            if ($company === null) {
                $this->error('The requested company UUID was not found.');

                return null;
            }

            $query->where('company_id', $company->getKey());
        } else {
            $query->limit($this->limit());
        }

        return $query->get();
    }

    private function limit(): int
    {
        $limit = (int) $this->option('limit');

        return min(500, max(1, $limit));
    }

    /** @param list<array<string, mixed>> $results */
    private function renderResults(array $results, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->table(
            ['Company', 'Status', 'Fetched', 'Created', 'Updated', 'Skipped', 'Conflicts', 'Message'],
            array_map(fn (array $row): array => [
                $row['company'],
                $row['status'],
                $row['articles_fetched'],
                $row['articles_created'],
                $row['articles_updated'],
                $row['articles_skipped'],
                $row['conflicts_raised'],
                $row['message'],
            ], $results),
        );
    }
}
