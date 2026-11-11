<?php

return [
    // Files must never be stored on the Laravel public disk or web root.
    // Choose an explicitly private local disk, or a dedicated private S3 bucket.
    'disk' => env('AQARI_ATTACHMENT_DISK', 'local'),

    // ClamAV is mandatory on upload. A missing/unresponsive scanner returns 503.
    'clamav_binary' => env('AQARI_CLAMAV_BINARY', 'clamscan'),
    'clamav_timeout_seconds' => (int) env('AQARI_CLAMAV_TIMEOUT_SECONDS', 30),
];
