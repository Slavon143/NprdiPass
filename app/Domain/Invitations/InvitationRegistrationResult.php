<?php

namespace App\Domain\Invitations;

use App\Models\CompanyMembership;
use App\Models\User;

final readonly class InvitationRegistrationResult
{
    public function __construct(
        public User $user,
        public CompanyMembership $membership,
    ) {}
}
