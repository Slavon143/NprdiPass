<?php

namespace App\Services\Fortnox\Contracts;

use App\Data\Fortnox\FortnoxAccessToken;
use App\Data\Fortnox\FortnoxArticlePage;
use App\Exceptions\Fortnox\FortnoxProviderException;

interface FortnoxProvider
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * @throws FortnoxProviderException
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): FortnoxAccessToken;

    /**
     * @throws FortnoxProviderException
     */
    public function refreshAccessToken(string $refreshToken, ?string $tenantId = null): FortnoxAccessToken;

    /**
     * @throws FortnoxProviderException
     */
    public function listArticles(string $accessToken, ?string $modifiedAfter = null, ?string $page = null): FortnoxArticlePage;
}
