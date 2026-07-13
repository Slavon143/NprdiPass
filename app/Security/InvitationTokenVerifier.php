<?php

namespace App\Security;

use App\Models\CompanyInvitation;

class InvitationTokenVerifier
{
    public function verify(CompanyInvitation $invitation, string $plainTextToken): bool
    {
        if ($plainTextToken === '' || strlen($plainTextToken) > 512) {
            return false;
        }

        $storedHash = $invitation->getAttribute('token_hash');

        return is_string($storedHash)
            && strlen($storedHash) === 64
            && hash_equals($storedHash, hash('sha256', $plainTextToken));
    }
}
