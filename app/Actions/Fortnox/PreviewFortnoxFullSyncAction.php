<?php

namespace App\Actions\Fortnox;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Models\Company;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\User;
use App\Services\Fortnox\FortnoxSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PreviewFortnoxFullSyncAction
{
    public function __construct(private readonly CompanyAuthorizer $authorizer, private readonly FortnoxSyncService $syncService) {}

    /** @return array<string, mixed> */
    public function execute(User $actor, Company $company): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
        if (app()->environment('production')) {
            throw ValidationException::withMessages(['preview' => 'Full catalogue preview and import are limited to the test environment.']);
        }
        $connection = FortnoxConnection::query()->forCompany($company)->first();
        if ($connection === null || $connection->status !== FortnoxConnectionStatus::Active) {
            throw ValidationException::withMessages(['connection' => 'An active Fortnox connection is required.']);
        }
        $pages = 0;
        $articles = $this->syncService->listAllArticles($actor, $company, $connection, onPage: function () use (&$pages): void {
            $pages++;
        });
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
        $numbers = array_values(array_unique(array_map(fn ($article) => $article->number, $articles)));
        $mapped = FortnoxArticleMapping::query()->forCompany($company)->where('connection_id', $connection->getKey())
            ->pluck('fortnox_article_number')->all();
        $preview = [
            'connection_uuid' => $connection->uuid,
            'watermark' => $connection->last_synced_at?->toIso8601String(),
            'total_articles' => count($numbers),
            'existing_mappings' => count(array_intersect($numbers, $mapped)),
            'unmapped_articles' => count(array_diff($numbers, $mapped)),
            'pages_fetched' => $pages,
            'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        ];
        $token = bin2hex(random_bytes(32));
        Cache::put($this->key($actor, $company, $token), $preview, now()->addMinutes(10));

        return $preview + ['token' => $token];
    }

    /** @return array<string, mixed> */
    public function consume(User $actor, Company $company, string $token): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
        if (app()->environment('production')) {
            throw ValidationException::withMessages(['preview' => 'Full catalogue preview and import are limited to the test environment.']);
        }
        $key = $this->key($actor, $company, $token);
        $preview = preg_match('/^[0-9a-f]{64}$/D', $token) === 1
            ? Cache::lock($key.':consume', 10)->get(fn () => Cache::pull($key)) : null;
        if (! is_array($preview) || CarbonImmutable::parse($preview['expires_at'])->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['preview' => 'The preview expired or is unavailable. Preview the full catalogue again.']);
        }

        return $preview;
    }

    private function key(User $actor, Company $company, string $token): string
    {
        return 'fortnox-full-preview:'.$company->getKey().':'.$actor->getKey().':'.hash('sha256', $token);
    }
}
