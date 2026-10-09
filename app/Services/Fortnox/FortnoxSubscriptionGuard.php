<?php

namespace App\Services\Fortnox;

use App\Exceptions\Fortnox\FortnoxSubscriptionBlocked;

class FortnoxSubscriptionGuard
{
    private const PRODUCTION_SUBSCRIPTION = '1853390';

    public function isProtectedEnvironment(): bool
    {
        return ! app()->environment('production');
    }

    public function expectedTenantId(): string
    {
        return (string) config('fortnox.nonproduction_tenant_id', '1854146');
    }

    public function assertAuthorizationEnabled(?string $clientId = null): void
    {
        if (! $this->isProtectedEnvironment()) {
            return;
        }

        $this->assertTenantAllowed($this->expectedTenantId());

        if (! config('fortnox.nonproduction_oauth_enabled', false)) {
            throw new FortnoxSubscriptionBlocked('New Fortnox authorizations are disabled in this environment.');
        }

        $this->assertTestClient($clientId);
    }

    public function assertTestClient(?string $clientId): void
    {
        if (! $this->isProtectedEnvironment()) {
            return;
        }

        // Portal ownership cannot be inferred from a customer's API tenant.
        // Pin the integration Client ID explicitly approved for this test setup.
        // It need not be owned by the same subscription as the API customer.
        $expected = (string) config('fortnox.nonproduction_client_id', '');
        if ($expected === '' || $clientId === null || ! hash_equals($expected, $clientId)) {
            throw new FortnoxSubscriptionBlocked('A verified Fortnox test client must be configured before authorization or token renewal.');
        }
    }

    public function assertTenantAllowed(?string $tenantId): void
    {
        if (! $this->isProtectedEnvironment()) {
            return;
        }

        if ($tenantId === self::PRODUCTION_SUBSCRIPTION) {
            throw new FortnoxSubscriptionBlocked('Fortnox production subscription 1853390 is blocked in this environment.');
        }

        if ($tenantId === null || preg_match('/^[1-9][0-9]*$/D', $tenantId) !== 1) {
            throw new FortnoxSubscriptionBlocked('The Fortnox subscription could not be identified. No connection was accepted.');
        }

        if ($tenantId !== $this->expectedTenantId()) {
            throw new FortnoxSubscriptionBlocked('The selected Fortnox subscription does not match the permitted test subscription.');
        }
    }

    /**
     * Read only tokens obtained from the authenticated Fortnox backchannel or
     * encrypted connection storage. This does not authenticate client-supplied
     * JWTs or verify their signatures. Fortnox documents tenantId in this claim.
     */
    public function tenantIdFromServerToken(string $accessToken): ?string
    {
        if (strlen($accessToken) > 16384) {
            return null;
        }

        $parts = explode('.', $accessToken);
        if (count($parts) !== 3 || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]) !== 1) {
            return null;
        }

        $encoded = strtr($parts[1], '-_', '+/');
        $decoded = base64_decode($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if ($decoded === false) {
            return null;
        }

        try {
            $claims = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $tenant = is_array($claims) ? ($claims['tenantId'] ?? null) : null;
        if (! is_string($tenant) && ! is_int($tenant)) {
            return null;
        }

        return preg_match('/^[1-9][0-9]*$/D', (string) $tenant) === 1 ? (string) $tenant : null;
    }

    public function assertStoredTokenAllowed(string $accessToken, ?string $recordedTenantId): ?string
    {
        $tenantId = $this->tenantIdFromServerToken($accessToken);
        if (! $this->isProtectedEnvironment()) {
            return $tenantId;
        }

        // Preserve a production connection even when a different token is offered.
        if ($recordedTenantId !== null) {
            $this->assertTenantAllowed($recordedTenantId);
        }
        $this->assertTenantAllowed($tenantId);
        if ($recordedTenantId !== null && $recordedTenantId !== $tenantId) {
            throw new FortnoxSubscriptionBlocked('The Fortnox token does not match the recorded subscription.');
        }

        return $tenantId;
    }
}
