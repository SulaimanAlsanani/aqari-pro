<?php
return [
    // Independent from APP_KEY to support key rotation and disaster recovery.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
];
