<?php
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Aqari Pro API');
})->purpose('Display application information');

// Owner provisioning is implemented only in app/Console/Commands/CreateOwner.php.
// Do not register a duplicate owner:create command here.

// Read-only monitoring only: automatic backups are NOT scheduled because the
// current SQLite writer requires operator-controlled maintenance mode.
if (config('backup.monitor_enabled', false)) {
    \Illuminate\Support\Facades\Schedule::command(
        'backup:status --disk=backup --max-age-hours='.(int) config('backup.max_age_hours', 26)
    )->hourly()->withoutOverlapping()->onOneServer();
}
