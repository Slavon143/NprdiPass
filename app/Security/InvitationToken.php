<?php

namespace App\Security;

final readonly class InvitationToken
{
    public function __construct(
        private string $plainText,
        private string $hash,
    ) {}

    public function plainText(): string
    {
        return $this->plainText;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * @return array<string, bool>
     */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }
}
