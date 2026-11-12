<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class BackupMakeGuardTest extends TestCase
{
    public function test_backup_refuses_to_run_outside_maintenance_mode(): void
    {
        self::assertSame(1,Artisan::call('backup:make'));
    }

    public function test_backup_refuses_non_sqlite_drivers(): void
    {
        $this->app->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, new class implements \Illuminate\Contracts\Foundation\MaintenanceMode {
            public function activate(array $payload): void {}
            public function deactivate(): void {}
            public function active(): bool { return true; }
            public function data(): array { return []; }
        });
        config()->set('database.default','mysql');
        self::assertSame(1,Artisan::call('backup:make'));
    }
}
