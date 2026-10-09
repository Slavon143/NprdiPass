<?php

namespace App\Console\Commands;

use App\Actions\Fortnox\ReconcileFortnoxAction;
use App\Data\Fortnox\FortnoxReconciliationReport;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FortnoxReconcileCommand extends Command
{
    protected $signature = 'fortnox:reconcile
        {--company= : Company UUID to reconcile}
        {--all-companies : Reconcile all active Fortnox connections}
        {--repair-missing-mappings : Create mappings for unmapped Fortnox articles that match an existing variant SKU}
        {--apply-deletions= : Deletion handling: report (default) or archive}
        {--limit=50 : Maximum number of connections to process}
        {--format=table : Output format (table or json)}';

    protected $description = 'Reconcile local Fortnox mappings against the full provider article list';

    public function handle(
        ReconcileFortnoxAction $reconcile,
        CurrentCompany $currentCompany,
        TokenCurrentCompany $tokenCurrentCompany,
    ): int {
        if (! (bool) config('features.fortnox_integration')) {
            $this->info('Fortnox integration is disabled; reconciliation skipped.');

            return self::SUCCESS;
        }

        $companyUuid = (string) ($this->option('company') ?? '');
        $allCompanies = (bool) $this->option('all-companies');

        if ($companyUuid === '' && ! $allCompanies) {
            $this->error('You must specify either --company or --all-companies.');

            return self::INVALID;
        }

        $deletionPolicy = (string) ($this->option('apply-deletions') ?? '');
        if ($deletionPolicy === '') {
            $deletionPolicy = (string) config('fortnox.reconciliation.deletion_policy', 'report');
        }

        if (! in_array($deletionPolicy, ['report', 'archive'], true)) {
            $this->error('--apply-deletions must be "report" or "archive".');

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
                $results[] = $this->row($company, null, 'The Fortnox connection has no connected user.');

                continue;
            }

            $currentCompany->clear();
            $tokenCurrentCompany->set($company);

            try {
                $report = $reconcile->execute(
                    $actor,
                    $company,
                    (bool) $this->option('repair-missing-mappings'),
                    $deletionPolicy,
                );

                $results[] = $this->row($company, $report);
            } catch (ValidationException $exception) {
                $results[] = $this->row($company, null, $exception->getMessage());
            } catch (Throwable $exception) {
                $failures++;
                $results[] = $this->row($company, null, $exception->getMessage());
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

    /** @return array<string, mixed> */
    private function row(Company $company, ?FortnoxReconciliationReport $report = null, ?string $message = null): array
    {
        return [
            'company' => $company->name,
            'company_uuid' => $company->uuid,
            'complete' => $report?->complete,
            'total_articles' => $report?->totalArticles,
            'missing_mappings' => $report !== null ? count($report->missingMappingArticleNumbers) : null,
            'orphaned_mappings' => $report !== null ? count($report->orphanedMappingArticleNumbers) : null,
            'repaired_mappings' => $report !== null ? count($report->repairedArticleNumbers) : null,
            'archived_products' => $report?->archivedProducts,
            'message' => $message,
        ];
    }

    /** @param  list<array<string, mixed>>  $results */
    private function renderResults(array $results, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->table(
            ['Company', 'Complete', 'Articles', 'Missing', 'Orphaned', 'Repaired', 'Archived', 'Message'],
            array_map(fn (array $row): array => [
                $row['company'],
                $row['complete'] ? 'yes' : 'no',
                (string) $row['total_articles'],
                (string) $row['missing_mappings'],
                (string) $row['orphaned_mappings'],
                (string) $row['repaired_mappings'],
                (string) $row['archived_products'],
                (string) ($row['message'] ?? ''),
            ], $results),
        );
    }
}
