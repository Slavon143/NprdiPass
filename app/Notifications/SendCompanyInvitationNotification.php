<?php

namespace App\Notifications;

use App\Domain\Invitations\PendingInvitation;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use UnexpectedValueException;

class SendCompanyInvitationNotification
{
    public function send(PendingInvitation $pendingInvitation, User $inviter): void
    {
        $invitation = $pendingInvitation->invitation();
        $company = Company::query()->findOrFail($invitation->getAttribute('company_id'));
        $uuid = (string) $invitation->getAttribute('uuid');
        $plainTextToken = $pendingInvitation->plainTextToken();
        $role = $invitation->getAttribute('role');
        $expiresAt = $invitation->getAttribute('expires_at');

        if (! $role instanceof CompanyRole || ! $expiresAt instanceof CarbonInterface) {
            throw new UnexpectedValueException('Invitation data is incomplete.');
        }

        $acceptUrl = route('invitations.show', [
            'invitation' => $uuid,
            'token' => $plainTextToken,
        ]);

        Notification::route('mail', (string) $invitation->getAttribute('email'))
            ->notify(new CompanyInvitationNotification(
                (string) $company->getAttribute('name'),
                (string) $inviter->getAttribute('name'),
                $role->value,
                $expiresAt->toIso8601String(),
                $acceptUrl,
            ));
    }
}
