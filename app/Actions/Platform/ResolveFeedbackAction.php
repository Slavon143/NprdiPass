<?php

namespace App\Actions\Platform;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Enums\PlatformPermission;
use App\Enums\UserStatus;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ResolveFeedbackAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, string $uuid): PlatformIncident
    {
        return DB::transaction(function () use ($actor, $uuid): PlatformIncident {
            $currentActor = User::query()->whereKey($actor->getKey())->sharedLock()->first();
            if ($currentActor === null || $currentActor->status !== UserStatus::Active) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize(PlatformPermission::FeedbackManage->value);

            // This permission covers platform feedback only, never tenant resources or operational incidents.
            $feedback = PlatformIncident::query()->where('uuid', $uuid)->where('type', 'customer_feedback')->lockForUpdate()->firstOrFail();
            if ($feedback->status === PlatformIncidentStatus::Resolved) {
                return $feedback;
            }
            $feedback->forceFill(['status' => PlatformIncidentStatus::Resolved, 'resolved_by' => $currentActor->getKey(), 'resolved_at' => now()])->save();
            $this->audit->logPlatform(AuditEvent::PlatformAction, $currentActor, $feedback, [
                'action' => 'feedback.resolved', 'feedback_uuid' => $feedback->uuid, 'company_uuid' => $feedback->company?->uuid,
            ]);

            return $feedback;
        });
    }
}
