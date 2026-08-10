<?php

return [
    'integration_test_enabled' => (bool) env('RUN_MEDIA_STORAGE_INTEGRATION', false),
    'upload' => [
        'public_endpoint' => env('AWS_PUBLIC_ENDPOINT', env('AWS_ENDPOINT')),
        'authority_ttl_minutes' => (int) env('MEDIA_UPLOAD_AUTHORITY_TTL_MINUTES', 15),
        'max_bytes' => (int) env('MEDIA_UPLOAD_MAX_BYTES', 100 * 1024 * 1024),
    ],
];
