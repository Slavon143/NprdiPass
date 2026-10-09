<?php

use App\Services\Webhooks\WebhookUrlSafety;

test('webhook connections pin the address that was validated and disable proxies', function () {
    $safety = new class extends WebhookUrlSafety
    {
        public int $lookups = 0;

        protected function resolveAddresses(string $host): array
        {
            $this->lookups++;

            return $this->lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        }
    };
    $options = $safety->connectionOptions('https://webhook.example:8443/events');
    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['webhook.example:8443:93.184.216.34'])
        ->and($options['proxy'])->toBe('')->and($safety->lookups)->toBe(1);
});

test('mixed public and private DNS answers reject the entire webhook destination', function () {
    $safety = new class extends WebhookUrlSafety
    {
        protected function resolveAddresses(string $host): array
        {
            return ['93.184.216.34', '127.0.0.1'];
        }
    };
    expect(fn () => $safety->connectionOptions('https://webhook.example/events'))->toThrow(InvalidArgumentException::class);
});
