<?php

namespace App\Enums\Webhooks;

enum WebhookEventType: string
{
    case PassportPublished = 'passport.published';
    case PassportUnpublished = 'passport.unpublished';
    case DocumentApproved = 'document.approved';
    case ImportCompleted = 'import.completed';
    case BillingSubscriptionUpdated = 'billing.subscription.updated';
}
