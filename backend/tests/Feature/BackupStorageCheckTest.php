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

    public function test_storage_preflight_requires_explicit_private_visibility(): void
    {
        config()->set('filesystems.disks.backup', ['driver' => 's3']);
        Storage::shouldReceive('disk')->never();
        self::assertSame(1, Artisan::call('backup:check-storage'));
        self::assertStringContainsString('Backup storage must be private', Artisan::output());
    }

    public function test_storage_preflight_round_trips_and_removes_private_probe(): void
    {
        config()->set('filesystems.disks.backup', ['driver' => 's3', 'visibility' => 'private']);
        // Fake disk lets this verify command logic without external credentials.
        Storage::fake('backup');
        self::assertSame(0, Artisan::call('backup:check-storage'));
        self::assertSame([], Storage::disk('backup')->allFiles('healthchecks'));
    }

    public function test_storage_adapter_construction_errors_fail_gracefully(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        Storage::shouldReceive('disk')->once()->with('backup')
            ->andThrow(new \RuntimeException('Synthetic adapter startup error'));

        self::assertSame(1,Artisan::call('backup:check-storage'));
        self::assertStringContainsString('verification failed',Artisan::output());
    }

    public function test_successful_probe_fails_if_cleanup_fails(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        $contents=null;
        $disk=\Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturnUsing(
            function ($path,$data,$options) use (&$contents): bool {
                $contents=$data;
                return true;
            }
        );
        $disk->shouldReceive('get')->once()->andReturnUsing(
            function ($path) use (&$contents) {
                return $contents;
            }
        );
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('backup')->andReturn($disk);

        self::assertSame(1,Artisan::call('backup:check-storage'));
        self::assertStringContainsString('Could not remove',Artisan::output());
    }

    public function test_probe_fails_if_adapter_reports_delete_success_but_file_remains(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        $contents=null;
        $disk=\Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturnUsing(
            function ($path,$data,$options) use (&$contents): bool {
                $contents=$data;
                return true;
            }
        );
        $disk->shouldReceive('get')->once()->andReturnUsing(
            function ($path) use (&$contents) {
                return $contents;
            }
        );
        $disk->shouldReceive('delete')->once()->andReturn(true);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('backup')->andReturn($disk);

        self::assertSame(1,Artisan::call('backup:check-storage'));
        self::assertStringContainsString('Could not verify removal',Artisan::output());
    }

    public function test_storage_preflight_rejects_missing_disk(): void
    {
        self::assertSame(1, Artisan::call('backup:check-storage', ['--disk' => 'missing-backup-disk']));
    }
}
