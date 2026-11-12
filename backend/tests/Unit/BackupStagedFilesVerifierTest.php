<?php
namespace Tests\Unit;

use App\Support\BackupStagedFilesVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class BackupStagedFilesVerifierTest extends TestCase
{
    private array $paths=[];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) unlink($path);
            if (is_dir($path)) rmdir($path);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $dir=sys_get_temp_dir().'/aqbk-stage-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $this->paths[]=$dir;
        $archive=tempnam(sys_get_temp_dir(),'aqbk-verify-');
        $this->paths[]=$archive;
        $database=$dir.'/database.mysql.sql';
        $contents='CREATE TABLE probe (id INT);';
        file_put_contents($database,$contents);
        $this->paths[]=$database;

        $zip=new ZipArchive();
        $zip->open($archive,ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('database.mysql.sql',$contents);
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.mysql.sql','files'=>[
                ['name'=>'database.mysql.sql','bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)],
            ],
        ],JSON_THROW_ON_ERROR));
        $zip->close();
        return [$archive,$dir,$database];
    }

    public function test_unmodified_staged_file_passes(): void
    {
        [$archive,$dir]=$this->fixture();
        self::assertSame(1,BackupStagedFilesVerifier::verify($archive,$dir));
    }

    public function test_changed_file_after_extract_is_rejected(): void
    {
        [$archive,$dir,$database]=$this->fixture();
        file_put_contents($database,'CREATE TABLE other (id INT);');
        $this->expectException(RuntimeException::class);
        BackupStagedFilesVerifier::verify($archive,$dir);
    }

    public function test_missing_file_after_extract_is_rejected(): void
    {
        [$archive,$dir,$database]=$this->fixture();
        unlink($database);
        $this->expectException(RuntimeException::class);
        BackupStagedFilesVerifier::verify($archive,$dir);
    }
}
