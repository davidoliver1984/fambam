<?php

return [
    'database_max_age_hours' => (int) env('BACKUP_DATABASE_MAX_AGE_HOURS', 26),
    'object_storage_max_age_hours' => (int) env('BACKUP_OBJECT_STORAGE_MAX_AGE_HOURS', 26),
    'restore_drill_max_age_days' => (int) env('BACKUP_RESTORE_DRILL_MAX_AGE_DAYS', 31),
];
