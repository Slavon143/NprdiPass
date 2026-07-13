<?php

namespace App\Listeners;

use App\Audit\AuditContext;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class WriteFailedLoginAuditLog
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AuditContext $context,
    ) {}

    public function handle(Failed $event): void
    {
        $rateKey = 'audit-login-failed|'.($this->context->clientIp() ?? 'unknown');
        $limit = max(1, (int) config('audit.failed_login_per_minute', 20));

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return;
        }

        RateLimiter::hit($rateKey, 60);
        $email = $event->credentials['email'] ?? null;
        $normalizedEmail = is_string($email)
            ? Str::lower(trim($email))
            : 'unknown';

        $this->auditLogger->logPlatform(
            AuditEvent::AuthLoginFailed,
            $event->user instanceof User ? $event->user : null,
            null,
            ['email' => mb_substr($normalizedEmail, 0, 255)],
        );
    }
}
