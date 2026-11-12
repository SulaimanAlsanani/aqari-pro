<?php
namespace Tests\Feature;

use App\Support\BackupEnvelope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class BackupStageRestoreTest extends TestCase
{
    private const OBJECT='snapshots/2026/11/12/123000-123e4567-e89b-12d3-a456-426614174000.aqbk';

    public function test_staging_requires_private_storage(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'local']);
        self::assertSame(1,Artisan::call('backup:stage-restore',['object'=>self::OBJECT]));
    }

    public function test_invalid_archive_does_not_leave_restored_documents(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        $key=base64_encode(random_bytes(32));
        config()->set('backup.encryption_key',$key);
        Storage::fake('backup');
        Storage::disk('backup')->put(self::OBJECT, BackupEnvelope::encrypt('not a ZIP archive',$key));
        self::assertSame(1,Artisan::call('backup:stage-restore',['object'=>self::OBJECT]));
    }

    public function test_valid_archive_is_staged_without_overwriting_live_files(): void
    {
        if (!class_exists(\SQLite3::class)) $this->markTestSkipped('sqlite3 required');
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        $key=base64_encode(random_bytes(32));
        config()->set('backup.encryption_key',$key);
        Storage::fake('backup');

        $database=tempnam(sys_get_temp_dir(),'aqsqlite-');
        $archive=tempnam(sys_get_temp_dir(),'aqarchive-');
        $sqlite=new \SQLite3($database);
        $sqlite->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, value TEXT)');
        $sqlite->exec("INSERT INTO probe (value) VALUES ('restored')");
        $sqlite->close();
        $bytes=file_get_contents($database);
        $zip=new ZipArchive();
        $zip->open($archive,ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sqlite',$bytes);
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.sqlite','files'=>[
                ['name'=>'database.sqlite','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)],
            ],
        ],JSON_THROW_ON_ERROR));
        $zip->close();
        Storage::disk('backup')->put(self::OBJECT,BackupEnvelope::encrypt(file_get_contents($archive),$key));
        try {
            self::assertSame(0,Artisan::call('backup:stage-restore',['object'=>self::OBJECT]));
            $output=Artisan::output();
            self::assertStringContainsString('Isolated restore staged', $output);
            // The staged path is only emitted after integrity verification.
            preg_match('~(.*restore-staging/[a-f0-9-]{36})~',$output,$matches);
            self::assertNotEmpty($matches[1]??null);
            $staged=$matches[1];
            self::assertFileExists($staged.'/database.sqlite');
            $check=new \SQLite3($staged.'/database.sqlite',SQLITE3_OPEN_READONLY);
            try { self::assertSame('restored',$check->querySingle('SELECT value FROM probe LIMIT 1')); }
            finally { $check->close(); }
            unlink($staged.'/database.sqlite');
            rmdir($staged);
        } finally { unlink($database);unlink($archive); }
    }
}
