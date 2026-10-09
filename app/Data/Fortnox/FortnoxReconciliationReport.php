<?php

namespace App\Data\Fortnox;

class FortnoxReconciliationReport
{
    /**
     * @param  list<string>  $missingMappingArticleNumbers  Fortnox articles without a local mapping
     * @param  list<string>  $orphanedMappingArticleNumbers  local mappings whose Fortnox article no longer exists
     * @param  list<string>  $repairedArticleNumbers  mappings created through SKU matching
     * @param  list<string>  $archiveFailures  product UUIDs that could not be archived
     */
    public function __construct(
        public readonly int $totalArticles,
        public readonly array $missingMappingArticleNumbers,
        public readonly array $orphanedMappingArticleNumbers,
        public readonly array $repairedArticleNumbers,
        public readonly int $archivedProducts,
        public readonly array $archiveFailures,
        public readonly bool $complete,
    ) {}
}
