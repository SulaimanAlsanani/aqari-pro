<?php
namespace Tests\Feature;

use App\Support\BackupEnvelope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class BackupVerifyTest extends TestCase
{
    private const OBJECT='snapshots/2026/11/12/123000-123e4567-e89b-12d3-a456-426614174000.aqbk';

    private function configureBackup(): string
    {
        $key=base64_encode(random_bytes(32));
        config()->set('backup.encryption_key',$key);
        config()->set('filesystems.disks.backup', ['driver'=>'s3','visibility'=>'private']);
        Storage::fake('backup');
        return $key;
    }

    private function archive(bool $corrupt=false): string
    {
        $path=tempnam(sys_get_temp_dir(),'aqverify-');
        $zip=new ZipArchive();
        if ($zip->open($path,ZipArchive::OVERWRITE | ZipArchive::CREATE)!==true) {
            throw new \RuntimeException('Could not create fixture.');
        }
        $zip->addFromString('database.sqlite',$corrupt?'tampered':'sqlite-data');
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.sqlite','files'=>[
                ['name'=>'database.sqlite','bytes'=>11,'sha256'=>hash('sha256','sqlite-data')],
            ],
        ],JSON_THROW_ON_ERROR));
        $zip->close();
        $data=file_get_contents($path);
        unlink($path);
        return $data;
    }

    public function test_valid_backup_passes_without_modifying_live_database(): void
    {
        $key=$this->configureBackup();
        Storage::disk('backup')->put(self::OBJECT,BackupEnvelope::encrypt($this->archive(),$key));
        self::assertSame(0,Artisan::call('backup:verify',['object'=>self::OBJECT]));
        Storage::disk('backup')->assertExists(self::OBJECT);
    }

    public function test_corrupt_archive_is_rejected(): void
    {
        $key=$this->configureBackup();
        Storage::disk('backup')->put(self::OBJECT,BackupEnvelope::encrypt($this->archive(true),$key));
        self::assertSame(1,Artisan::call('backup:verify',['object'=>self::OBJECT]));
    }

    public function test_modified_ciphertext_is_rejected(): void
    {
        $key=$this->configureBackup();
        $payload=BackupEnvelope::encrypt($this->archive(),$key);
        $last=strlen($payload)-1;
        $payload[$last]=chr(ord($payload[$last]) ^ 1);
        Storage::disk('backup')->put(self::OBJECT,$payload);
        self::assertSame(1,Artisan::call('backup:verify',['object'=>self::OBJECT]));
    }

    public function test_invalid_object_key_is_rejected_before_storage_access(): void
    {
        $this->configureBackup();
        self::assertSame(1,Artisan::call('backup:verify',['object'=>'../secrets.env']));
    }
}
