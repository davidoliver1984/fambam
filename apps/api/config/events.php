<?php

return [
    'admission_lifetime_days' => (int) env('EVENT_ADMISSION_LIFETIME_DAYS', 30),
    'export_lifetime_hours' => (int) env('EVENT_EXPORT_LIFETIME_HOURS', 24),
    'export_download_ttl_minutes' => (int) env('EVENT_EXPORT_DOWNLOAD_TTL_MINUTES', 5),
];
