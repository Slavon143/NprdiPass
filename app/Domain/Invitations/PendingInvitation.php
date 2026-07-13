<?php

namespace App\Domain\Invitations;

use App\Models\CompanyInvitation;

final readonly class PendingInvitation
{
    public function __construct(
        private CompanyInvitation $invitation,
        private string $plainTextToken,
    ) {}

    public function invitation(): CompanyInvitation
    {
        return $this->invitation;
    }

    public function plainTextToken(): string
    {
        return $this->plainTextToken;
    }

    /**
     * @return array<string, bool>
     */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }
}
