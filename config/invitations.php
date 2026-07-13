<?php

return [
    'expires_hours' => (int) env('INVITATION_EXPIRES_HOURS', 72),
    'retention_days' => (int) env('INVITATION_RETENTION_DAYS', 180),
    'mailer' => env('INVITATION_MAILER', 'array'),
];
