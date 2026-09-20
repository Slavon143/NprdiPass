<?php

namespace App\Services\Notifications;

use App\Enums\Notifications\NotificationSeverity;
use App\Enums\Notifications\NotificationType;
use App\Models\Company;
use App\Models\Notifications\Notification;
use App\Models\Notifications\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationCenter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, Notification>
     */
    public function notifyCompany(
        Company $company,
        NotificationType $type,
        string $title,
        ?string $body,
        string $idempotencyKey,
        NotificationSeverity $severity = NotificationSeverity::Info,
        ?string $actionUrl = null,
        array $payload = [],
    ): Collection {
        $notifications = collect();

        $company->memberships()
            ->with('user')
            ->whereHas('user')
            ->each(function ($membership) use ($company, $type, $title, $body, $idempotencyKey, $severity, $actionUrl, $payload, $notifications): void {
                $user = $membership->user;

                if ($user instanceof User) {
                    $notification = $this->notifyUser(
                        $company,
                        $user,
                        $type,
                        $title,
                        $body,
                        $idempotencyKey,
                        $severity,
                        $actionUrl,
                        $payload,
                    );

                    if ($notification instanceof Notification) {
                        $notifications->push($notification);
                    }
                }
            });

        return $notifications;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function notifyUser(
        Company $company,
        User $user,
        NotificationType $type,
        string $title,
        ?string $body,
        string $idempotencyKey,
        NotificationSeverity $severity = NotificationSeverity::Info,
        ?string $actionUrl = null,
        array $payload = [],
    ): ?Notification {
        if (! $this->inAppEnabled($company, $user, $type)) {
            return null;
        }

        return Notification::query()->firstOrCreate(
            [
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'type' => $type,
                'preference_key' => $type->value,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl,
                'payload' => $payload,
            ],
        );
    }

    public function inAppEnabled(Company $company, User $user, NotificationType $type): bool
    {
        $preference = NotificationPreference::query()
            ->forCompany($company)
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->first();

        if (! $preference instanceof NotificationPreference) {
            return true;
        }

        if (! $preference->in_app_enabled) {
            return false;
        }

        return $preference->muted_until === null || $preference->muted_until->isPast();
    }
}
