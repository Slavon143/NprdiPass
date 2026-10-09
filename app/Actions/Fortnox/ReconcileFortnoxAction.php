<?php

namespace App\Actions\Fortnox;

use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Data\Fortnox\FortnoxArticle;
use App\Data\Fortnox\FortnoxReconciliationReport;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\User;
use App\Services\Fortnox\FortnoxSyncService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReconcileFortnoxAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly FortnoxSyncService $syncService,
        private readonly AuditLogger $auditLogger,
        private readonly CatalogIdentifierNormalizer $normalizer,
        private readonly ArchiveProductAction $archiveProduct,
    ) {}

    public function execute(
        User $actor,
        Company $company,
        bool $repairMissingMappings = false,
        string $deletionPolicy = 'report',
    ): FortnoxReconciliationReport {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        $connection = FortnoxConnection::query()->forCompany($company)->first();

        if ($connection === null || $connection->status === FortnoxConnectionStatus::Revoked) {
            throw ValidationException::withMessages(['connection' => 'Fortnox is not connected.']);
        }

        if ($connection->status === FortnoxConnectionStatus::ReconnectRequired) {
            throw ValidationException::withMessages(['connection' => 'Fortnox requires re-authentication before reconciliation.']);
        }

        if (! in_array($deletionPolicy, ['report', 'archive'], true)) {
            throw ValidationException::withMessages(['deletion_policy' => 'Deletion policy must be "report" or "archive".']);
        }

        try {
            $articles = $this->syncService->listAllArticles($actor, $company, $connection);
            $complete = true;
        } catch (FortnoxProviderException $exception) {
            if (! str_contains($exception->getMessage(), 'safety cap exceeded')) {
                throw $exception;
            }

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxReconciled, $actor, $connection, [
                'complete' => false,
                'failure_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return new FortnoxReconciliationReport(0, [], [], [], 0, [], false);
        }

        $fortnoxNumbers = collect($articles)->pluck('number')->unique()->values()->all();
        $mappings = FortnoxArticleMapping::query()
            ->forCompany($company)
            ->where('connection_id', $connection->getKey())
            ->get();
        $mappedNumbers = $mappings->pluck('fortnox_article_number')->all();

        $missing = array_values(array_diff($fortnoxNumbers, $mappedNumbers));
        $orphaned = array_values(array_diff($mappedNumbers, $fortnoxNumbers));

        $repaired = $repairMissingMappings && $missing !== []
            ? $this->repairMappings($actor, $company, $connection, collect($articles), $missing)
            : [];

        $archived = 0;
        $archiveFailures = [];

        if ($deletionPolicy === 'archive' && $orphaned !== []) {
            [$archived, $archiveFailures] = $this->applyDeletions($actor, $company, $mappings, $orphaned);
        }

        $this->auditLogger->logTenant($company, AuditEvent::FortnoxReconciled, $actor, $connection, [
            'complete' => true,
            'total_articles' => count($articles),
            'missing_mappings' => count($missing),
            'orphaned_mappings' => count($orphaned),
            'repaired_mappings' => count($repaired),
            'archived_products' => $archived,
            'deletion_policy' => $deletionPolicy,
        ]);

        return new FortnoxReconciliationReport(
            totalArticles: count($articles),
            missingMappingArticleNumbers: $missing,
            orphanedMappingArticleNumbers: $orphaned,
            repairedArticleNumbers: $repaired,
            archivedProducts: $archived,
            archiveFailures: $archiveFailures,
            complete: $complete,
        );
    }

    /**
     * @param  Collection<int, FortnoxArticle>  $articles
     * @param  list<string>  $missing
     * @return list<string>
     */
    private function repairMappings(User $actor, Company $company, FortnoxConnection $connection, Collection $articles, array $missing): array
    {
        $repaired = [];

        foreach ($articles as $article) {
            if (! in_array($article->number, $missing, true)) {
                continue;
            }

            try {
                $created = DB::transaction(function () use ($actor, $company, $connection, $article): bool {
                    $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
                    $variant = ProductVariant::query()
                        ->forCompany($company)
                        ->where('sku_normalized', $this->normalizer->normalizeSku($article->number))
                        ->sharedLock()
                        ->first();
                    if (! $variant instanceof ProductVariant) {
                        return false;
                    }
                    FortnoxArticleMapping::query()->create([
                        'company_id' => $company->getKey(),
                        'connection_id' => $connection->getKey(),
                        'fortnox_article_number' => $article->number,
                        'fortnox_article_id' => $article->id !== '' ? $article->id : null,
                        'product_id' => $variant->product_id,
                        'variant_id' => $variant->getKey(),
                    ]);

                    return true;
                });
            } catch (QueryException) {
                continue;
            }

            if ($created) {
                $repaired[] = $article->number;
            }
        }

        return $repaired;
    }

    /**
     * @param  Collection<int, FortnoxArticleMapping>  $mappings
     * @param  list<string>  $orphaned
     * @return array{int, list<string>}
     */
    private function applyDeletions(User $actor, Company $company, Collection $mappings, array $orphaned): array
    {
        $orphanedSet = array_flip($orphaned);
        $archived = 0;
        $failures = [];

        $productIds = $mappings
            ->whereIn('fortnox_article_number', $orphaned)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($productIds as $productId) {
            $product = Product::query()->forCompany($company)->find($productId);

            if (! $product instanceof Product) {
                continue;
            }

            $productArticles = FortnoxArticleMapping::query()
                ->forCompany($company)
                ->where('product_id', $productId)
                ->pluck('fortnox_article_number')
                ->all();

            // Only archive when every Fortnox article behind this product
            // disappeared from the provider.
            if (array_diff($productArticles, $orphaned) !== []) {
                continue;
            }

            try {
                DB::transaction(function () use ($actor, $company, $product): void {
                    $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
                    $this->archiveProduct->execute($actor, $company, $product);
                });
                $archived++;
            } catch (Throwable) {
                $failures[] = $product->uuid;
            }
        }

        return [$archived, $failures];
    }
}
