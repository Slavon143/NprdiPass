<?php

return [
    'delivery_timeout_seconds' => (int) env('WEBHOOK_DELIVERY_TIMEOUT_SECONDS', 10),
    'signature_tolerance_seconds' => (int) env('WEBHOOK_SIGNATURE_TOLERANCE_SECONDS', 300),
    'response_excerpt_bytes' => (int) env('WEBHOOK_RESPONSE_EXCERPT_BYTES', 2000),
];
