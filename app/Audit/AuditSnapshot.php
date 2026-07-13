<?php

namespace App\Audit;

use App\Enums\CompanyRole;
use App\Models\CompanyInvitation;
use App\Models\User;

class AuditSnapshot
{
    /** @return array<string, mixed> */
    public function invitation(CompanyInvitation $invitation): array
    {
        $inviterId = $invitation->getAttribute('invited_by');
        $inviterEmail = is_int($inviterId)
            ? User::withTrashed()->whereKey($inviterId)->value('email')
            : null;
        $role = $invitation->getAttribute('role');
        $expiresAt = $invitation->getAttribute('expires_at');

        return [
            'invitation_uuid' => $invitation->getAttribute('uuid'),
            'email' => $invitation->getAttribute('email'),
            'role' => $role instanceof CompanyRole ? $role->value : null,
            'expires_at' => $expiresAt instanceof \DateTimeInterface ? $expiresAt->format(DATE_ATOM) : null,
            'invited_by' => is_string($inviterEmail) ? $inviterEmail : null,
        ];
    }

    /** @return array<string, mixed> */
    public function member(User $user): array
    {
        return [
            'target_user_uuid' => $user->getAttribute('uuid'),
            'target_email' => $user->getAttribute('email'),
        ];
    }
}
