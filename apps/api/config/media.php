<?php

return [
    'integration_test_enabled' => (bool) env('RUN_MEDIA_STORAGE_INTEGRATION', false),
    'upload' => [
        'public_endpoint' => env('AWS_PUBLIC_ENDPOINT', env('AWS_ENDPOINT')),
        'authority_ttl_minutes' => (int) env('MEDIA_UPLOAD_AUTHORITY_TTL_MINUTES', 15),
        'max_bytes' => (int) env('MEDIA_UPLOAD_MAX_BYTES', 100 * 1024 * 1024),
    ],
    'delivery' => [
        'authority_ttl_minutes' => (int) env('MEDIA_DELIVERY_AUTHORITY_TTL_MINUTES', 5),
    ],
    'validation' => [
        'max_pixels' => (int) env('MEDIA_MAX_PIXELS', 100_000_000),
        'decoder_timeout_seconds' => (float) env('MEDIA_DECODER_TIMEOUT_SECONDS', 20),
        'imagemagick_binary' => env('IMAGEMAGICK_BINARY', 'magick'),
        'memory_limit' => env('IMAGEMAGICK_MEMORY_LIMIT', '256MiB'),
        'map_limit' => env('IMAGEMAGICK_MAP_LIMIT', '512MiB'),
        'disk_limit' => env('IMAGEMAGICK_DISK_LIMIT', '1GiB'),
    ],
    'malware' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout_seconds' => (float) env('CLAMAV_TIMEOUT_SECONDS', 30),
    ],
    'cleanup' => [
        'quarantine_retention_days' => (int) env('MEDIA_QUARANTINE_RETENTION_DAYS', 7),
    ],
    'processing' => [
        'exiftool_binary' => env('EXIFTOOL_BINARY', 'exiftool'),
        'timeout_seconds' => (float) env('MEDIA_PROCESSING_TIMEOUT_SECONDS', 30),
        'metadata_profile_max_bytes' => (int) env('MEDIA_METADATA_PROFILE_MAX_BYTES', 32 * 1024 * 1024),
        'canonical_jpeg_quality' => (int) env('MEDIA_CANONICAL_JPEG_QUALITY', 90),
        'variant_processing_version' => (int) env('MEDIA_VARIANT_PROCESSING_VERSION', 1),
        'variant_webp_quality' => (int) env('MEDIA_VARIANT_WEBP_QUALITY', 82),
        'integration_test_enabled' => env('RUN_MEDIA_PROCESSING_INTEGRATION', false),
    ],
];
