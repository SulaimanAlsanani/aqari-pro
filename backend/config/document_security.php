<?php
return [
 // The scanner is required in production, regardless of local test settings.
 'scan_enabled'=>app()->environment('production') || (bool) env('DOCUMENT_SCAN_ENABLED',false),
 'clamscan_binary'=>env('DOCUMENT_CLAMSCAN_BINARY','/usr/bin/clamscan'),
 'scan_timeout_seconds'=>max(1,(int) env('DOCUMENT_SCAN_TIMEOUT',30)),
];
