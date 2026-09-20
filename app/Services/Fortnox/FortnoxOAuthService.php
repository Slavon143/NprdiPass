<?php

namespace App\Services\Fortnox;

use App\Data\Fortnox\FortnoxAccessToken;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use Illuminate\Support\Str;

class FortnoxOAuthService
{
    public function __construct(private readonly FortnoxProvider $provider) {}

    public function authorizationUrl(string $state): string
    {
        return $this->provider->authorizationUrl($state, $this->redirectUri());
    }

    /**
     * @throws FortnoxProviderException
     */
    public function exchange(string $code): FortnoxAccessToken
    {
        return $this->provider->exchangeAuthorizationCode($code, $this->redirectUri());
    }

    public function redirectUri(): string
    {
        $path = (string) config('fortnox.redirect_path');

        if ($path === '') {
            return '';
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return rtrim($path, '/');
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
