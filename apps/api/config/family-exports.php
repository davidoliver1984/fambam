<?php

return [
    'lifetime_hours' => (int) env('FAMILY_EXPORT_LIFETIME_HOURS', 24),
    'download_ttl_minutes' => (int) env('FAMILY_EXPORT_DOWNLOAD_TTL_MINUTES', 5),
];
