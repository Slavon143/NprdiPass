<?php

namespace App\Services\Fortnox;

use App\Data\Fortnox\FortnoxAccessToken;
use App\Data\Fortnox\FortnoxArticle;
use App\Data\Fortnox\FortnoxArticlePage;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Carbon\CarbonImmutable;

/**
 * In-process provider used by local/testing. Tests seed articles and simulate
 * provider failures through explicit hooks; no network is involved.
 */
class FakeFortnoxProvider implements FortnoxProvider
{
    /** @var list<array<string, mixed>> */
    private array $articles = [];

    /** @var list<array{operation: string, exception: FortnoxProviderException}> */
    private array $failures = [];

    private int $pageSize = 100;

    public function __construct()
    {
        $this->pageSize = max(1, (int) config('fortnox.sync_page_size'));
    }

    public function setPageSize(int $pageSize): self
    {
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    /**
     * @param  list<array{id?: string, number: string, name?: string|null, description?: string|null, price?: float|null, stock?: float|null, gtin?: string|null, lastModified?: string|null}>  $articles
     */
    public function seedArticles(array $articles): self
    {
        $this->articles = $articles;

        return $this;
    }

    /** @param  list<array{id?: string, number: string, name?: string|null, description?: string|null, price?: float|null, stock?: float|null, gtin?: string|null, lastModified?: string|null}>  $articles */
    public function addArticles(array $articles): self
    {
        $this->articles = [...$this->articles, ...$articles];

        return $this;
    }

    public function failNext(string $operation, FortnoxProviderException $exception): self
    {
        $this->failures[] = ['operation' => $operation, 'exception' => $exception];

        return $this;
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        // The fake provider never contacts the real Fortnox OAuth server.
        // The browser is redirected straight back to the local callback with
        // a simulated authorization code, so the full flow works without any
        // Fortnox credentials in local/testing.
        if ((string) config('fortnox.client_id') === '') {
            return $redirectUri.'?'.http_build_query([
                'code' => 'fake-code',
                'state' => $state,
            ]);
        }

        $base = rtrim((string) config('fortnox.authorize_url'), '/');

        return $base.'?'.http_build_query([
            'client_id' => (string) config('fortnox.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('fortnox.scopes')),
            'state' => $state,
            'access_type' => 'offline',
        ]);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): FortnoxAccessToken
    {
        $this->throwIfScheduled('exchange');
        if ($code === '') {
            throw FortnoxProviderException::unauthorized('Fake Fortnox rejected the authorization code.');
        }

        return $this->token();
    }

    public function refreshAccessToken(string $refreshToken): FortnoxAccessToken
    {
        $this->throwIfScheduled('refresh');
        if ($refreshToken === '') {
            throw FortnoxProviderException::unauthorized('Fake Fortnox rejected the refresh token.');
        }

        return $this->token();
    }

    public function listArticles(string $accessToken, ?string $modifiedAfter = null, ?string $page = null): FortnoxArticlePage
    {
        $this->throwIfScheduled('list_articles');
        if ($accessToken === '') {
            throw FortnoxProviderException::unauthorized();
        }

        $modified = $modifiedAfter === null || $modifiedAfter === ''
            ? null
            : CarbonImmutable::parse($modifiedAfter);

        $articles = array_values(array_filter(
            $this->articles,
            fn (array $article): bool => $modified === null
                || ($article['lastModified'] ?? null) === null
                || CarbonImmutable::parse((string) $article['lastModified'])->greaterThan($modified),
        ));

        $pageNumber = max(1, (int) ($page ?? 1));
        $offset = ($pageNumber - 1) * $this->pageSize;
        $slice = array_slice($articles, $offset, $this->pageSize);
        $nextPage = $offset + $this->pageSize < count($articles) ? (string) ($pageNumber + 1) : null;

        return new FortnoxArticlePage(
            array_map(fn (array $article): FortnoxArticle => $this->hydrate($article), $slice),
            $nextPage,
        );
    }

    private function token(): FortnoxAccessToken
    {
        return new FortnoxAccessToken(
            accessToken: 'fake-access-'.bin2hex(random_bytes(8)),
            refreshToken: 'fake-refresh-'.bin2hex(random_bytes(8)),
            expiresAt: CarbonImmutable::now()->addHour(),
            scope: implode(' ', (array) config('fortnox.scopes')),
            tenantId: 'fake-tenant',
        );
    }

    /** @param array<string, mixed> $article */
    private function hydrate(array $article): FortnoxArticle
    {
        return new FortnoxArticle(
            id: (string) ($article['id'] ?? ''),
            number: (string) $article['number'],
            name: isset($article['name']) ? (string) $article['name'] : null,
            description: isset($article['description']) ? (string) $article['description'] : null,
            price: isset($article['price']) ? (float) $article['price'] : null,
            stock: isset($article['stock']) ? (float) $article['stock'] : null,
            gtin: isset($article['gtin']) ? (string) $article['gtin'] : null,
            lastModified: CarbonImmutable::parse((string) ($article['lastModified'] ?? now()->toIso8601String())),
        );
    }

    private function throwIfScheduled(string $operation): void
    {
        foreach ($this->failures as $index => $failure) {
            if ($failure['operation'] !== $operation) {
                continue;
            }

            unset($this->failures[$index]);

            throw $failure['exception'];
        }
    }

    /** @return list<array<string, mixed>> */
    public function seededArticles(): array
    {
        return $this->articles;
    }
}
