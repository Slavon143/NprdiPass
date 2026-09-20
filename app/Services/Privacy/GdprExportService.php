<?php

namespace App\Services\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class GdprExportService
{
    /**
     * Build a machine-readable export of all personal data related to the user.
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $memberships = $user->memberships()->with('company')->get();
            $companyIds = $memberships->pluck('company_id')->all();

            return [
                'format' => 'nordipass-gdpr-export',
                'format_version' => 1,
                'exported_at' => now()->toISOString(),
                'profile' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status->value,
                    'email_verified_at' => $user->email_verified_at?->toISOString(),
                    'last_login_at' => $user->last_login_at?->toISOString(),
                    'created_at' => $user->created_at?->toISOString(),
                    'updated_at' => $user->updated_at?->toISOString(),
                ],
                'memberships' => $memberships->map(fn ($membership): array => [
                    'company' => $membership->company?->name,
                    'company_uuid' => $membership->company?->uuid,
                    'role' => $membership->role->value,
                    'is_owner' => $membership->is_owner,
                    'joined_at' => $membership->joined_at?->toISOString(),
                ])->all(),
                'invitations_sent' => $user->invitationsSent()
                    ->get()
                    ->map(fn ($invitation): array => [
                        'email' => $invitation->email,
                        'role' => $invitation->role,
                        'created_at' => $invitation->created_at?->toISOString(),
                        'expires_at' => $invitation->expires_at->toISOString(),
                        'accepted_at' => $invitation->accepted_at?->toISOString(),
                        'cancelled_at' => $invitation->cancelled_at ?? null,
                    ])
                    ->all(),
                'notifications' => $user->tenantNotifications()
                    ->get()
                    ->map(fn ($notification): array => [
                        'type' => $notification->type->value,
                        'title' => $notification->title,
                        'created_at' => $notification->created_at?->toISOString(),
                        'read_at' => $notification->read_at?->toISOString(),
                    ])
                    ->all(),
                'notification_preferences' => $user->notificationPreferences()
                    ->get()
                    ->map(fn ($preference): array => [
                        'type' => $preference->type,
                        'in_app_enabled' => $preference->in_app_enabled,
                        'email_enabled' => $preference->email_enabled,
                        'muted_until' => $preference->muted_until?->toISOString(),
                    ])
                    ->all(),
                'api_tokens' => $user->tokens()
                    ->get()
                    ->map(fn ($token): array => [
                        'name' => $token->name,
                        'abilities' => $token->abilities,
                        'created_at' => $token->created_at?->toISOString(),
                        'last_used_at' => $token->last_used_at?->toISOString(),
                    ])
                    ->all(),
                'jobs' => $user->jobStatuses()
                    ->whereIn('company_id', $companyIds)
                    ->get()
                    ->map(fn ($job): array => [
                        'name' => $job->name,
                        'type' => $job->type,
                        'status' => $job->status->value,
                        'created_at' => $job->created_at?->toISOString(),
                        'completed_at' => $job->completed_at?->toISOString(),
                    ])
                    ->all(),
                'audit_entries_as_actor' => $user->actions()
                    ->latest('id')
                    ->limit((int) config('privacy.export_audit_max_rows', 1000))
                    ->get()
                    ->map(fn ($audit): array => [
                        'event' => $audit->event,
                        'log_name' => $audit->log_name,
                        'created_at' => $audit->created_at?->toISOString(),
                    ])
                    ->all(),
            ];
        });
    }
}
