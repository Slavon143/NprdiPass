<?php

namespace App\Services\Fortnox;

use App\Authorization\CompanyAuthorizer;
use App\Data\Fortnox\FortnoxArticle;
use App\Data\Fortnox\FortnoxArticlePage;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class FortnoxSyncService
{
    public function __construct(
        private readonly FortnoxProvider $provider,
        private readonly FortnoxArticleApplier $applier,
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    public function execute(User $actor, Company $company, FortnoxConnection $connection, FortnoxSyncRun $run, ?Closure $assertLock = null): FortnoxSyncRun
    {
        try {
            $accessToken = $this->freshAccessToken($company, $connection, $actor);
            $watermark = $run->watermark_before ?? $connection->last_synced_at;
            $modifiedAfter = $watermark?->toIso8601String();
            $maximum = max(1, (int) config('fortnox.sync_max_articles_per_run'));
            $fetched = (int) $run->articles_fetched;
            $segmentFetched = 0;
            $maxModified = $run->watermark_observed ?? $watermark;
            $page = $run->cursor_page;
            // Pre-upgrade attempts did not checkpoint their high watermark.
            if ($page !== null && $run->watermark_observed === null) {
                $page = null;
                $fetched = 0;
            }

            while (true) {
                $result = $this->fetchWithRetries($run, fn (): FortnoxArticlePage => $this->provider->listArticles(
                    $accessToken,
                    $modifiedAfter,
                    $page === null ? null : (string) $page,
                ));

                $nextPage = $result->nextPage === null ? null : (int) $result->nextPage;
                $paginationComplete = $nextPage === null;
                DB::transaction(function () use ($assertLock, $result, $actor, $company, $connection, $run, &$fetched, &$segmentFetched, &$maxModified, $nextPage, $paginationComplete): void {
                    $assertLock?->__invoke();
                    $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
                    foreach ($result->articles as $article) {
                        $fetched++;
                        $segmentFetched++;
                        if ($maxModified === null || $article->lastModified->greaterThan($maxModified)) {
                            $maxModified = $article->lastModified;
                        }
                        $this->applier->apply($actor, $company, $connection, $run, $article);
                    }

                    // Persist the resumable cursor after every page so a crashed
                    // process can continue from the last completed page.
                    $run->forceFill([
                        'articles_fetched' => $fetched,
                        'watermark_observed' => $maxModified,
                        'cursor_page' => $paginationComplete ? null : $nextPage,
                        'pagination_complete' => $paginationComplete,
                    ])->save();
                });

                if ($paginationComplete || $segmentFetched >= $maximum) {
                    break;
                }

                $page = $nextPage;
            }

            DB::transaction(function () use ($assertLock, $actor, $company, $connection, $run, $fetched, $maxModified, $paginationComplete): void {
                $assertLock?->__invoke();
                $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
                $locked = FortnoxConnection::query()
                    ->forCompany($company)
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // The committed watermark advances only when pagination fully
                // completed. When the per-run article cap cut pagination short,
                // last_synced_at stays untouched and the next trigger resumes
                // the same logical attempt from the saved cursor.
                if ($paginationComplete) {
                    $locked->forceFill([
                        'last_synced_at' => $locked->last_synced_at !== null && ($maxModified === null || $locked->last_synced_at->greaterThan($maxModified))
                            ? $locked->last_synced_at : $maxModified,
                        'status' => FortnoxConnectionStatus::Active,
                        'failure_message' => null,
                    ])->save();
                }

                $run->forceFill([
                    'articles_fetched' => $fetched,
                    'status' => FortnoxSyncRunStatus::Completed,
                    'pagination_complete' => $paginationComplete,
                    'cursor_page' => $paginationComplete ? null : $run->cursor_page,
                    'completed_at' => now(),
                ])->save();
            });

            return $run->refresh();
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }
    }

    /**
     * Exhaustively fetch every article visible for the connection, walking all
     * pages without the per-run sync cap. Used by reconciliation. Throws when
     * the safety cap is exceeded so callers can refuse to apply mutations.
     *
     * @return list<FortnoxArticle>
     */
    public function listAllArticles(User $actor, Company $company, FortnoxConnection $connection, ?string $modifiedAfter = null, ?int $maximum = null): array
    {
        $maximum = max(1, $maximum ?? (int) config('fortnox.reconciliation.max_articles'));
        $accessToken = $this->freshAccessToken($company, $connection, $actor);
        $articles = [];
        $page = null;

        while (true) {
            $result = $this->fetchWithRetries(null, fn (): FortnoxArticlePage => $this->provider->listArticles(
                $accessToken,
                $modifiedAfter,
                $page === null ? null : (string) $page,
            ));

            foreach ($result->articles as $article) {
                $articles[] = $article;
            }

            $nextPage = $result->nextPage === null ? null : (int) $result->nextPage;
            if ($nextPage === null) {
                return $articles;
            }

            if (count($articles) >= $maximum) {
                throw new FortnoxProviderException(
                    "Fortnox reconciliation safety cap exceeded ({$maximum} articles).",
                    false,
                    0,
                );
            }

            $page = $nextPage;
        }
    }

    private function freshAccessToken(Company $company, FortnoxConnection $connection, User $actor): string
    {
        try {
            return DB::transaction(function () use ($actor, $company, $connection): string {
                $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
                $locked = FortnoxConnection::query()
                    ->forCompany($company)
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $payload = $locked->token_payload ?? [];
                $accessToken = (string) ($payload['access_token'] ?? '');
                $expiresAt = $locked->token_expires_at;
                $margin = max(0, (int) config('fortnox.token_refresh_margin_seconds'));

                if ($accessToken !== '' && $expiresAt !== null && $expiresAt->greaterThan(now()->addSeconds($margin))) {
                    return $accessToken;
                }

                $refreshToken = (string) ($payload['refresh_token'] ?? '');
                if ($refreshToken === '') {
                    throw FortnoxProviderException::unauthorized('Fortnox tokens are missing. Re-connect the integration.');
                }

                $token = $this->provider->refreshAccessToken($refreshToken);

                $locked->forceFill([
                    'token_payload' => [
                        'access_token' => $token->accessToken,
                        'refresh_token' => $token->refreshToken ?? $refreshToken,
                        'scope' => $token->scope,
                    ],
                    'token_expires_at' => $token->expiresAt,
                    'status' => FortnoxConnectionStatus::Active,
                    'failure_message' => null,
                ])->save();

                return $token->accessToken;
            });
        } catch (FortnoxProviderException $exception) {
            if (! $exception->retryable) {
                FortnoxConnection::query()
                    ->forCompany($company)
                    ->whereKey($connection->getKey())
                    ->update([
                        'status' => FortnoxConnectionStatus::ReconnectRequired,
                        'failure_message' => mb_substr($exception->getMessage(), 0, 2000),
                    ]);
            }

            throw $exception;
        }
    }

    /** @param callable(): FortnoxArticlePage $fetch */
    private function fetchWithRetries(?FortnoxSyncRun $run, callable $fetch): FortnoxArticlePage
    {
        $maximum = max(1, (int) config('fortnox.retry.max_attempts'));
        $baseDelay = max(0, (int) config('fortnox.retry.base_delay_ms'));
        $multiplier = max(1, (int) config('fortnox.retry.backoff_multiplier'));
        $attempt = 0;

        while (true) {
            $attempt++;
            if ($run !== null) {
                $run->increment('attempts');
            }

            try {
                return $fetch();
            } catch (FortnoxProviderException $exception) {
                if (! $exception->retryable || $attempt >= $maximum) {
                    throw $exception;
                }

                $delay = $baseDelay * ($multiplier ** ($attempt - 1));
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
    }

    private function failRun(FortnoxSyncRun $run, Throwable $exception): void
    {
        $run->forceFill([
            'status' => FortnoxSyncRunStatus::Failed,
            'failure_message' => mb_substr($exception->getMessage() !== '' ? $exception->getMessage() : 'Fortnox sync failed.', 0, 2000),
            'completed_at' => now(),
            'pagination_complete' => false,
        ])->save();
    }
}
