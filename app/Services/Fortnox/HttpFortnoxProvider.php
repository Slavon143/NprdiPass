<?php

namespace App\Services\Fortnox;

use App\Data\Fortnox\FortnoxAccessToken;
use App\Data\Fortnox\FortnoxArticle;
use App\Data\Fortnox\FortnoxArticlePage;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Real provider over HTTPS with SSRF controls: HTTPS-only, host allowlist,
 * rejection of IP literals in private/reserved ranges, and typed error mapping.
 */
class HttpFortnoxProvider implements FortnoxProvider
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->config['base_url'] ??= (string) config('fortnox.base_url');
        $this->config['authorize_url'] ??= (string) config('fortnox.authorize_url');
        $this->config['token_url'] ??= (string) config('fortnox.token_url');
        $this->config['client_id'] ??= (string) config('fortnox.client_id');
        $this->config['client_secret'] ??= (string) config('fortnox.client_secret');
        $this->config['ca_bundle'] ??= (string) config('fortnox.ca_bundle');
        $this->config['allowed_api_hosts'] ??= (array) config('fortnox.allowed_api_hosts');
        $this->config['scopes'] ??= (array) config('fortnox.scopes');
        $this->config['sync_page_size'] ??= (int) config('fortnox.sync_page_size');
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        app(FortnoxSubscriptionGuard::class)->assertAuthorizationEnabled((string) $this->config['client_id']);
        $base = rtrim((string) ($this->config['authorize_url'] ?? ''), '/');
        $this->assertSafeExternalUrl($base);

        return $base.'?'.http_build_query([
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', (array) ($this->config['scopes'] ?? [])),
            'state' => $state,
            'access_type' => 'offline',
        ]);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): FortnoxAccessToken
    {
        app(FortnoxSubscriptionGuard::class)->assertAuthorizationEnabled((string) $this->config['client_id']);
        $response = $this->tokenRequest('authorization_code', [
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        return $this->tokenFrom($response->json());
    }

    public function refreshAccessToken(string $refreshToken, ?string $tenantId = null): FortnoxAccessToken
    {
        app(FortnoxSubscriptionGuard::class)->assertTenantAllowed($tenantId);
        app(FortnoxSubscriptionGuard::class)->assertTestClient((string) $this->config['client_id']);
        $response = $this->tokenRequest('refresh_token', [
            'refresh_token' => $refreshToken,
        ]);

        return $this->tokenFrom($response->json());
    }

    public function listArticles(string $accessToken, ?string $modifiedAfter = null, ?string $page = null): FortnoxArticlePage
    {
        $guard = app(FortnoxSubscriptionGuard::class);
        $guard->assertTenantAllowed($guard->tenantIdFromServerToken($accessToken));
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $this->assertSafeExternalUrl($base);

        $query = [
            'limit' => max(1, (int) ($this->config['sync_page_size'] ?? 100)),
        ];
        if ($modifiedAfter !== null && $modifiedAfter !== '') {
            $query['lastmodified'] = CarbonImmutable::parse($modifiedAfter)->format('Y-m-d H:i');
        }
        if ($page !== null && $page !== '') {
            $query['page'] = (int) $page;
        }

        $response = $this->send(fn () => $this->request()
            ->withToken($accessToken)
            ->timeout(30)
            ->get($base.'/3/articles', $query), 'Fortnox article listing failed.');

        $payload = $response->json();
        if (! is_array($payload)) {
            throw FortnoxProviderException::retryable('Fortnox returned an unreadable article payload.', $response->status());
        }

        $articles = [];
        foreach (($payload['Articles'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $articles[] = new FortnoxArticle(
                id: (string) ($raw['@url'] ?? $raw['ArticleNumber'] ?? ''),
                number: (string) ($raw['ArticleNumber'] ?? ''),
                name: isset($raw['Description']) ? (string) $raw['Description'] : null,
                description: isset($raw['Description']) ? (string) $raw['Description'] : null,
                price: isset($raw['SalesPrice']) ? (float) $raw['SalesPrice'] : null,
                stock: isset($raw['Stock']) ? (float) $raw['Stock'] : null,
                gtin: isset($raw['EAN']) ? (string) $raw['EAN'] : null,
                lastModified: CarbonImmutable::parse((string) ($raw['@lastModified'] ?? now()->toIso8601String())),
            );
        }

        $current = (int) ($payload['MetaInformation']['@CurrentPage'] ?? 1);
        $total = (int) ($payload['MetaInformation']['@TotalPages'] ?? 1);

        return new FortnoxArticlePage($articles, $current < $total ? (string) ($current + 1) : null);
    }

    /**
     * @param  array<string, string>  $form
     * @return Response
     */
    private function tokenRequest(string $grantType, array $form)
    {
        $tokenUrl = (string) ($this->config['token_url'] ?? '');
        $this->assertSafeExternalUrl($tokenUrl);

        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            throw FortnoxProviderException::unauthorized('Fortnox client credentials are not configured.');
        }

        return $this->send(
            fn () => $this->request()
                ->withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->timeout(30)
                ->post($tokenUrl, ['grant_type' => $grantType, ...$form]),
            'Fortnox token request failed.',
        );
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson();
        $caBundle = (string) ($this->config['ca_bundle'] ?? '');

        return $caBundle === ''
            ? $request
            : $request->withOptions(['verify' => $caBundle]);
    }

    /**
     * @param  callable(): Response  $request
     * @return Response
     */
    private function send(callable $request, string $failureMessage)
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw FortnoxProviderException::retryable($failureMessage.' '.$exception->getMessage());
        }

        if ($response->failed()) {
            $details = $this->failureDetails($response);
            $message = $details === '' ? $failureMessage : $failureMessage.' '.$details;

            throw match (true) {
                $response->status() === 401 || $response->status() === 403 => FortnoxProviderException::unauthorized($details === '' ? $failureMessage.' The credentials were rejected.' : $message, $response->status()),
                $response->status() === 429 || $response->serverError() => FortnoxProviderException::retryable($message, $response->status()),
                default => new FortnoxProviderException($message, false, $response->status()),
            };
        }

        return $response;
    }

    private function failureDetails(Response $response): string
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return '';
        }

        $error = $payload['ErrorInformation'] ?? null;
        if (! is_array($error)) {
            return '';
        }

        $code = (string) ($error['code'] ?? '');
        $message = (string) ($error['message'] ?? '');

        return trim(($code !== '' ? 'Fortnox code '.$code.'.' : '').($message !== '' ? ' '.$message : ''));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function tokenFrom(mixed $payload): FortnoxAccessToken
    {
        if (! is_array($payload)) {
            throw FortnoxProviderException::retryable('Fortnox token response is unreadable.');
        }

        $accessToken = (string) ($payload['access_token'] ?? '');
        if ($accessToken === '') {
            throw FortnoxProviderException::retryable('Fortnox token response is missing an access token.');
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        $guard = app(FortnoxSubscriptionGuard::class);
        $tenantId = $guard->tenantIdFromServerToken($accessToken);
        $guard->assertTenantAllowed($tenantId);

        return new FortnoxAccessToken(
            accessToken: $accessToken,
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            expiresAt: $expiresIn > 0 ? CarbonImmutable::now()->addSeconds($expiresIn) : null,
            scope: isset($payload['scope']) ? (string) $payload['scope'] : null,
            tenantId: $tenantId,
        );
    }

    public function assertSafeExternalUrl(string $url): void
    {
        if ($url === '') {
            throw new FortnoxProviderException('Fortnox URL is not configured.', false, 0);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if ($scheme !== 'https') {
            throw new FortnoxProviderException('Fortnox URLs must use HTTPS.', false, 0);
        }

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new FortnoxProviderException('Fortnox URL host is invalid.', false, 0);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIp($host)) {
                throw new FortnoxProviderException('Fortnox URLs must not target private or reserved addresses.', false, 0);
            }

            return;
        }

        $allowed = array_map(
            fn (string $allowedHost): string => Str::lower(rtrim($allowedHost, '.')),
            (array) ($this->config['allowed_api_hosts'] ?? []),
        );

        if (! in_array(Str::lower(rtrim($host, '.')), $allowed, true)) {
            throw new FortnoxProviderException('Fortnox URL host is not allowlisted.', false, 0);
        }
    }

    private function isPublicIp(string $host): bool
    {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
