<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\Models\Activity;

class AuditLog extends Activity
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit logs are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Individual audit logs cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function eventLabel(): string
    {
        $event = $this->getAttribute('event');

        return is_string($event)
            ? (AuditEvent::tryFrom($event)?->label() ?? 'Audit event')
            : 'Audit event';
    }

    public function actorLabel(): string
    {
        return (string) ($this->getProperty('actor_name')
            ?: $this->getProperty('actor_email')
            ?: 'System');
    }

    public function subjectLabel(): string
    {
        return (string) ($this->getProperty('target_email')
            ?: $this->getProperty('email')
            ?: $this->getProperty('subject_label')
            ?: $this->getProperty('company_name')
            ?: 'NordiPass');
    }

    public function summary(): string
    {
        $event = $this->getAttribute('event');

        if ($event === AuditEvent::CompanyUpdated->value) {
            $changes = $this->getProperty('changes', []);
            $count = is_array($changes) ? count($changes) : 0;

            return trans_choice(':count field changed|:count fields changed', $count, ['count' => $count]);
        }

        if ($event === AuditEvent::MemberRoleChanged->value) {
            return sprintf(
                '%s → %s',
                (string) $this->getProperty('old_role', 'unknown'),
                (string) $this->getProperty('new_role', 'unknown'),
            );
        }

        if ($event === AuditEvent::CompanySwitched->value) {
            return sprintf(
                '%s → %s',
                (string) $this->getProperty('from_company_name', 'No company'),
                (string) $this->getProperty('company_name', 'Company'),
            );
        }

        $role = $this->getProperty('role') ?: $this->getProperty('removed_role');

        return is_string($role) ? ucfirst($role) : $this->eventLabel();
    }
}
