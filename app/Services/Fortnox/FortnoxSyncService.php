<?php

namespace App\Services\Fortnox;

use App\Data\Fortnox\FortnoxArticlePage;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Illuminate\Support\Facades\DB;
use Throwable;

class FortnoxSyncService
{
    public function __construct(
        private readonly FortnoxProvider $provider,
        private readonly FortnoxArticleApplier $applier,
    ) {}

    public function execute(User $actor, Company $company, FortnoxConnection $connection, FortnoxSyncRun $run): FortnoxSyncRun
    {
        try {
            $accessToken = $this->freshAccessToken($company, $connection);
            $modifiedAfter = $connection->last_synced_at?->toIso8601String();
            $maximum = max(1, (int) config('fortnox.sync_max_articles_per_run'));
            $fetched = 0;
            $page = null;

            do {
                $result = $this->fetchWithRetries($run, fn (): FortnoxArticlePage => $this->provider->listArticles(
                    $accessToken,
                    $modifiedAfter,
                    $page,
                ));

                foreach ($result->articles as $article) {
                    $fetched++;
                    $this->applier->apply($actor, $company, $connection, $run, $article);
                }

                $page = $result->nextPage;
            } while ($page !== null && $fetched < $maximum);

            DB::transaction(function () use ($company, $connection, $run, $fetched): void {
                $locked = FortnoxConnection::query()
                    ->forCompany($company)
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $locked->forceFill([
                    'last_synced_at' => now(),
                    'status' => FortnoxConnectionStatus::Active,
                    'failure_message' => null,
                ])->save();

                $run->forceFill([
                    'articles_fetched' => $fetched,
                    'status' => FortnoxSyncRunStatus::Completed,
                    'completed_at' => now(),
                ])->save();
            });

            return $run->refresh();
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }
    }

    private function freshAccessToken(Company $company, FortnoxConnection $connection): string
    {
        try {
            return DB::transaction(function () use ($company, $connection): string {
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
    private function fetchWithRetries(FortnoxSyncRun $run, callable $fetch): FortnoxArticlePage
    {
        $maximum = max(1, (int) config('fortnox.retry.max_attempts'));
        $baseDelay = max(0, (int) config('fortnox.retry.base_delay_ms'));
        $multiplier = max(1, (int) config('fortnox.retry.backoff_multiplier'));
        $attempt = 0;

        while (true) {
            $attempt++;
            $run->increment('attempts');

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
        ])->save();
    }
}
