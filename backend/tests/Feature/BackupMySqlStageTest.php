<?php
namespace Tests\Feature;

use App\Support\BackupEnvelope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class BackupMySqlStageTest extends TestCase
{
    private const OBJECT='snapshots/2026/11/12/123000-123e4567-e89b-12d3-a456-426614174000.aqbk';

    public function test_mysql_dump_is_staged_as_data_and_never_executed(): void
    {
        config()->set('filesystems.disks.backup',['driver'=>'s3','visibility'=>'private']);
        $key=base64_encode(random_bytes(32));
        config()->set('backup.encryption_key',$key);
        Storage::fake('backup');

        $dump="-- MySQL dump\nCREATE DATABASE dangerous_name;\nUSE dangerous_name;\n";
        $file=tempnam(sys_get_temp_dir(),'aqmysql-');
        $zip=new ZipArchive();
        $this->assertSame(true,$zip->open($file,ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database.mysql.sql',$dump);
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.mysql.sql','files'=>[
                ['name'=>'database.mysql.sql','bytes'=>strlen($dump),'sha256'=>hash('sha256',$dump)],
            ],
        ],JSON_THROW_ON_ERROR));
        $zip->close();
        Storage::disk('backup')->put(self::OBJECT,BackupEnvelope::encrypt(file_get_contents($file),$key));

        try {
            $this->assertSame(0,Artisan::call('backup:stage-restore',['object'=>self::OBJECT]));
            $output=Artisan::output();
            $this->assertStringContainsString('NOT imported',$output);
            preg_match('~(/[^\s]*restore-staging/[a-f0-9-]{36})~',$output,$matches);
            $this->assertNotEmpty($matches[1]??null);
            $directory=$matches[1];
            $this->assertSame($dump,file_get_contents($directory.'/database.mysql.sql'));
            unlink($directory.'/database.mysql.sql');
            rmdir($directory);
        } finally {
            unlink($file);
        }
    }
}
