<?php

namespace App\Listeners;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Events\Logout;

class WriteLogoutAuditLog
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $properties = ['email' => $event->user->getAttribute('email')];
        $company = $this->currentCompany->get();

        if ($company === null) {
            $this->auditLogger->logPlatform(
                AuditEvent::AuthLogout,
                $event->user,
                $event->user,
                $properties,
            );

            return;
        }

        $this->auditLogger->logTenant(
            $company,
            AuditEvent::AuthLogout,
            $event->user,
            $event->user,
            $properties,
        );
    }
}
