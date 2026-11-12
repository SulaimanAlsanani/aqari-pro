<?php
return [
    // Independent from APP_KEY to support key rotation and disaster recovery.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
    'monitor_enabled' => (bool) env('BACKUP_MONITOR_ENABLED', false),
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
];
