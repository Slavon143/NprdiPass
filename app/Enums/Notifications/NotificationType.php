<?php

namespace App\Enums\Notifications;

enum NotificationType: string
{
    case DocumentExpiry = 'document.expiry';
    case ImportCompleted = 'import.completed';
    case ImportFailed = 'import.failed';
    case FortnoxSyncFailed = 'fortnox.sync_failed';
    case PublicationFailed = 'publication.failed';
    case ReadinessChanged = 'readiness.changed';
    case BillingEvent = 'billing.event';
    case FeedbackReply = 'feedback.reply';
}
