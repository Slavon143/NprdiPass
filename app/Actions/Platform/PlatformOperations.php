<?php

namespace App\Actions\Platform;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Platform\PlatformIncidentSeverity;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Models\Company;
use App\Models\Platform\FeatureFlagOverride;
use App\Models\Platform\PlatformIncident;
use App\Models\Platform\SupportAccessGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlatformOperations
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function grantSupportAccess(User $actor, Company $company, string $reason, int $minutes): SupportAccessGrant
    {
        return DB::transaction(function () use ($actor, $company, $reason, $minutes): SupportAccessGrant {
            $grant = SupportAccessGrant::query()->create([
                'company_id' => $company->getKey(),
                'granted_by' => $actor->getKey(),
                'reason' => $reason,
                'expires_at' => now()->addMinutes(max(15, min($minutes, 1440))),
            ]);

            $this->auditLogger->logPlatform(AuditEvent::PlatformAction, $actor, $company, [
                'action' => 'support_access.granted',
                'support_access_uuid' => $grant->uuid,
                'expires_at' => $grant->expires_at->toISOString(),
                'company_uuid' => $company->uuid,
                'company_name' => $company->name,
            ]);

            return $grant;
        });
    }

    public function revokeSupportAccess(User $actor, SupportAccessGrant $grant): SupportAccessGrant
    {
        $grant->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->getKey(),
        ])->save();

        $this->auditLogger->logPlatform(AuditEvent::PlatformAction, $actor, $grant->company, [
            'action' => 'support_access.revoked',
            'support_access_uuid' => $grant->uuid,
            'company_uuid' => $grant->company?->uuid,
            'company_name' => $grant->company?->name,
        ]);

        return $grant;
    }

    public function setFeatureFlag(User $actor, ?Company $company, string $flag, bool $enabled, string $reason): FeatureFlagOverride
    {
        $override = FeatureFlagOverride::query()->updateOrCreate(
            ['company_id' => $company?->getKey(), 'flag' => $flag],
            ['enabled' => $enabled, 'reason' => $reason, 'updated_by' => $actor->getKey()],
        );

        $this->auditLogger->logPlatform(AuditEvent::PlatformAction, $actor, $company, [
            'action' => 'feature_flag.updated',
            'flag' => $flag,
            'enabled' => $enabled,
            'scope' => $company instanceof Company ? 'company' : 'global',
            'company_uuid' => $company?->uuid,
            'company_name' => $company?->name,
        ]);

        return $override;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function reportIncident(
        User $actor,
        ?Company $company,
        string $type,
        PlatformIncidentSeverity $severity,
        string $title,
        ?string $description = null,
        array $context = [],
    ): PlatformIncident {
        $incident = PlatformIncident::query()->create([
            'company_id' => $company?->getKey(),
            'type' => $type,
            'severity' => $severity,
            'status' => PlatformIncidentStatus::Open,
            'title' => $title,
            'description' => $description,
            'context' => $context,
            'reported_by' => $actor->getKey(),
        ]);

        $this->auditLogger->logPlatform(AuditEvent::PlatformAction, $actor, $company, [
            'action' => 'incident.reported',
            'incident_uuid' => $incident->uuid,
            'severity' => $severity->value,
            'company_uuid' => $company?->uuid,
            'company_name' => $company?->name,
        ]);

        return $incident;
    }

    public function resolveIncident(User $actor, PlatformIncident $incident): PlatformIncident
    {
        $incident->forceFill([
            'status' => PlatformIncidentStatus::Resolved,
            'resolved_by' => $actor->getKey(),
            'resolved_at' => now(),
        ])->save();

        $this->auditLogger->logPlatform(AuditEvent::PlatformAction, $actor, $incident->company, [
            'action' => 'incident.resolved',
            'incident_uuid' => $incident->uuid,
            'company_uuid' => $incident->company?->uuid,
            'company_name' => $incident->company?->name,
        ]);

        return $incident;
    }
}
