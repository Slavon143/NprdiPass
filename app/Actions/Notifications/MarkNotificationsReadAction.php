<?php

namespace App\Actions\Notifications;

use App\Models\Company;
use App\Models\Notifications\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;

class MarkNotificationsReadAction
{
    /** @param list<int> $notificationIds */
    public function execute(User $user, Company $company, array $notificationIds): CarbonImmutable
    {
        $readAt = CarbonImmutable::now();

        if ($notificationIds !== []) {
            Notification::query()
                ->forCompany($company)
                ->where('user_id', $user->getKey())
                ->whereIn('id', $notificationIds)
                ->whereNull('dismissed_at')
                ->whereNull('read_at')
                ->update(['read_at' => $readAt]);
        }

        return $readAt;
    }
}
