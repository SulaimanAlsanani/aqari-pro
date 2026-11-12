<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupStatusTest extends TestCase
{
    private const OBJECT='snapshots/2026/11/12/120000-123e4567-e89b-12d3-a456-426614174000.aqbk';

    private function fakePrivateBackup(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        Storage::fake('backup');
    }

    public function test_rejects_nonprivate_disk_and_invalid_age(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'local','visibility'=>'private']);
        self::assertSame(1,Artisan::call('backup:status'));
        $this->fakePrivateBackup();
        self::assertSame(1,Artisan::call('backup:status',['--max-age-hours'=>0]));
        self::assertSame(1,Artisan::call('backup:status',['--max-age-hours'=>10000]));
    }

    public function test_missing_snapshots_raise_failure(): void
    {
        $this->fakePrivateBackup();
        self::assertSame(1,Artisan::call('backup:status'));
        self::assertStringContainsString('STALE',Artisan::output());
    }

    public function test_recent_private_object_is_detected(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 12:31:00','UTC'));
        $this->fakePrivateBackup();
        Storage::disk('backup')->put(self::OBJECT,str_repeat('x',64));
        self::assertSame(0,Artisan::call('backup:status'));
        self::assertStringContainsString('FRESH',Artisan::output());
        $this->travelBack();
    }

    public function test_unrelated_or_too_small_objects_do_not_count(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 12:31:00','UTC'));
        $this->fakePrivateBackup();
        Storage::disk('backup')->put('snapshots/2026/11/12/invalid.aqbk',str_repeat('x',64));
        Storage::disk('backup')->put(self::OBJECT,'tiny');
        self::assertSame(1,Artisan::call('backup:status'));
        $this->travelBack();
    }
}
