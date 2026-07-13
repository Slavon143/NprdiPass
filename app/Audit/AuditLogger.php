<?php

namespace App\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Support\ActivityLogger as SpatieActivityLogger;

class AuditLogger
{
    public function __construct(
        private readonly SpatieActivityLogger $activityLogger,
        private readonly AuditContext $context,
        private readonly SensitiveDataSanitizer $sanitizer,
    ) {}

    /** @param array<string, mixed> $properties */
    public function logTenant(
        Company $company,
        AuditEvent $event,
        ?User $actor = null,
        Model|string|null $subject = null,
        array $properties = [],
    ): AuditLog {
        return $this->write($company, $event, $actor, $subject, $properties);
    }

    /** @param array<string, mixed> $properties */
    public function logPlatform(
        AuditEvent $event,
        ?User $actor = null,
        Model|string|null $subject = null,
        array $properties = [],
    ): AuditLog {
        return $this->write(null, $event, $actor, $subject, $properties);
    }

    /** @param array<string, mixed> $properties */
    private function write(
        ?Company $company,
        AuditEvent $event,
        ?User $actor,
        Model|string|null $subject,
        array $properties,
    ): AuditLog {
        $safeProperties = $this->sanitizer->sanitize(array_merge(
            $properties,
            $this->subjectSnapshot($subject),
            $this->actorSnapshot($actor),
            $this->companySnapshot($company),
        ));
        $metadata = $this->context->metadata();

        $logger = $this->activityLogger
            ->useLog($company === null ? 'platform' : 'tenant')
            ->event($event->value)
            ->withProperties($safeProperties)
            ->tap(function (ActivityContract $activity) use ($company, $metadata): void {
                if (! $activity instanceof AuditLog) {
                    throw new LogicException('The configured activity model is not an AuditLog.');
                }

                $activity->setAttribute('company_id', $company?->getKey());
                $activity->setAttribute('ip_address', $metadata['ip_address']);
                $activity->setAttribute('user_agent', $metadata['user_agent']);
                $activity->setAttribute('request_id', $metadata['request_id']);
            });

        if ($actor === null) {
            $logger->causedByAnonymous();
        } else {
            $logger->causedBy($actor);
        }

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        $activity = $logger->log($event->value);

        if (! $activity instanceof AuditLog) {
            throw new LogicException('Audit logging is disabled or misconfigured.');
        }

        return $activity;
    }

    /** @return array<string, mixed> */
    private function actorSnapshot(?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        return [
            'actor_email' => $actor->getAttribute('email'),
            'actor_name' => $actor->getAttribute('name'),
        ];
    }

    /** @return array<string, mixed> */
    private function companySnapshot(?Company $company): array
    {
        if ($company === null) {
            return [];
        }

        return [
            'company_uuid' => $company->getAttribute('uuid'),
            'company_name' => $company->getAttribute('name'),
        ];
    }

    /** @return array<string, mixed> */
    private function subjectSnapshot(Model|string|null $subject): array
    {
        if (is_string($subject)) {
            return ['subject_label' => mb_substr($subject, 0, 255)];
        }

        if ($subject instanceof Company) {
            return ['subject_label' => $subject->getAttribute('name')];
        }

        if ($subject instanceof User) {
            return ['subject_label' => $subject->getAttribute('email')];
        }

        return [];
    }
}
