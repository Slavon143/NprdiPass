<?php

namespace App\Data\Fortnox;

use Carbon\CarbonImmutable;

class FortnoxAccessToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $scope,
        public readonly ?string $tenantId,
    ) {}
}
