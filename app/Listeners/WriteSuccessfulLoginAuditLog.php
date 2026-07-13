<?php

namespace App\Listeners;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Login;

class WriteSuccessfulLoginAuditLog
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogger->logPlatform(
            AuditEvent::AuthLogin,
            $event->user,
            $event->user,
            ['email' => $event->user->getAttribute('email')],
        );
    }
}
