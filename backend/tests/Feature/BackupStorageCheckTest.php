<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupStorageCheckTest extends TestCase
{
    public function test_storage_preflight_rejects_local_driver(): void
    {
        config()->set('filesystems.disks.backup', ['driver' => 'local', 'root' => storage_path('app/private')]);
        self::assertSame(1, Artisan::call('backup:check-storage'));
    }

    public function test_storage_preflight_rejects_public_bucket(): void
    {
        config()->set('filesystems.disks.backup', ['driver' => 's3', 'visibility' => 'public']);
        self::assertSame(1, Artisan::call('backup:check-storage'));
    }

    public function test_storage_preflight_round_trips_and_removes_private_probe(): void
    {
        config()->set('filesystems.disks.backup', ['driver' => 's3', 'visibility' => 'private']);
        // Fake disk lets this verify command logic without external credentials.
        Storage::fake('backup');
        self::assertSame(0, Artisan::call('backup:check-storage'));
        self::assertSame([], Storage::disk('backup')->allFiles('healthchecks'));
    }

    public function test_storage_preflight_rejects_missing_disk(): void
    {
        self::assertSame(1, Artisan::call('backup:check-storage', ['--disk' => 'missing-backup-disk']));
    }
}
