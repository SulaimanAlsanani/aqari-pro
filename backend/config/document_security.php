<?php
return [
 // The scanner is required in production, regardless of local test settings.
 'scan_enabled'=>env('APP_ENV','local') === 'production' || (bool) env('DOCUMENT_SCAN_ENABLED',false),
 'clamscan_binary'=>env('DOCUMENT_CLAMSCAN_BINARY','/usr/bin/clamscan'),
 'scan_timeout_seconds'=>max(1,(int) env('DOCUMENT_SCAN_TIMEOUT',30)),
 'signature_dir'=>env('DOCUMENT_SIGNATURE_DIR','/var/lib/clamav'),
 'signature_max_age_hours'=>max(1,min(720,(int)env('DOCUMENT_SIGNATURE_MAX_AGE_HOURS',168))),
];
