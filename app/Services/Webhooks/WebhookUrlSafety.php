<?php

namespace App\Services\Webhooks;

use InvalidArgumentException;

class WebhookUrlSafety
{
    public function assertSafe(string $url, bool $resolveDns = true): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = trim($host, '[]');

        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URL must be an HTTPS URL without credentials.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($host);

            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Webhook URL host is not publicly routable.');
        }

        if (! $resolveDns) {
            return;
        }

        $addresses = $this->resolveAddresses($host);

        if ($addresses === []) {
            throw new InvalidArgumentException('Webhook URL host could not be resolved.');
        }

        foreach (array_unique($addresses) as $address) {
            $this->assertPublicIp($address);
        }
    }

    /** @return array<string, mixed> */
    public function connectionOptions(string $url): array
    {
        $this->assertSafe($url, false);
        if (! extension_loaded('curl')) {
            throw new InvalidArgumentException('Webhook delivery requires the cURL transport.');
        }
        $parts = parse_url($url);
        $host = trim(strtolower($parts['host']), '[]');
        $port = $parts['port'] ?? 443;
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolveAddresses($host);
        if ($addresses === []) {
            throw new InvalidArgumentException('Webhook URL host could not be resolved.');
        }
        foreach ($addresses as $address) {
            $this->assertPublicIp($address);
        }
        $address = $addresses[0];
        $pinned = str_contains($address, ':') ? '['.$address.']' : $address;

        return ['allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinned]]];
    }

    /** @return list<string> */
    protected function resolveAddresses(string $host): array
    {
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function assertPublicIp(string $address): void
    {
        if (str_starts_with(strtolower($address), '::ffff:')) {
            throw new InvalidArgumentException('IPv4-mapped webhook addresses are not allowed.');
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('Webhook URL resolves to a private, loopback, link-local, or reserved address.');
        }
    }
}
