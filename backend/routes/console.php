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


/**
 * Runtime release gate: checks Laravel's effective configuration and the
 * connected MySQL schema, not just values rendered in Docker Compose.
 * Read-only and intentionally restricted to isolated staging.
 */
Artisan::command('staging:assert-isolation', function () {
    $appUrl = (string) config('app.url', '');
    $hostname = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
    $expectedHost = strtolower((string) env('AQARI_STAGING_DOMAIN', ''));
    $checks = [
        'APP_ENV must be staging' => app()->environment('staging'),
        'APP_DEBUG must be disabled' => config('app.debug') === false,
        'Staging must use MySQL' => config('database.default') === 'mysql',
        'MySQL host must be the isolated Compose database service' =>
            config('database.connections.mysql.host') === 'db',
        'Configured schema must be aqari_staging' =>
            config('database.connections.mysql.database') === 'aqari_staging',
        'Configured MySQL user must be aqari_staging' =>
            config('database.connections.mysql.username') === 'aqari_staging',
        'Malware scanning must be enabled' => config('document_security.scan_enabled') === true,
        'Application URL must be the dedicated staging HTTPS origin' =>
            $expectedHost !== '' &&
            str_starts_with($expectedHost, 'staging.') &&
            $hostname === $expectedHost &&
            $appUrl === 'https://'.$expectedHost,
    ];
    foreach ($checks as $reason => $valid) {
        if (!$valid) {
            $this->error('FAIL: '.$reason);
            return 1;
        }
    }
    try {
        $actual = \Illuminate\Support\Facades\DB::connection('mysql')
            ->selectOne('SELECT DATABASE() AS schema_name, CURRENT_USER() AS db_user');
        $schema = (string) ($actual->schema_name ?? '');
        $dbUser = (string) ($actual->db_user ?? '');
        if ($schema !== 'aqari_staging' ||
            !str_starts_with($dbUser, 'aqari_staging@')) {
            $this->error('FAIL: Connected MySQL database or user differs from isolated staging');
            return 1;
        }
    } catch (\Throwable $exception) {
        // Do not print SQL credentials, DSNs or exception traces.
        $this->error('FAIL: Could not verify the staging database connection');
        return 1;
    }
    $this->info('PASS: Laravel runtime settings and connected MySQL staging identity');
    return 0;
})->purpose('Read-only staging Laravel configuration and MySQL isolation check');
