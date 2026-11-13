<?php
return [
    // Explicit opt-in for a destructive-in-practice, synthetic-data E2E drill.
    // Do NOT enable this in production or on a real owner's account.
    'e2e_write_enabled'=>filter_var(env('STAGING_E2E_WRITE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'e2e_owner_email'=>env('STAGING_E2E_OWNER_EMAIL', ''),
];
