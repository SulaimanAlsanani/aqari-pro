<?php
namespace Tests\Unit;

use App\Support\BackupArchiveValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class BackupArchiveValidatorTest extends TestCase
{
    private function makeArchive(bool $tamper): string
    {
        $path=tempnam(sys_get_temp_dir(),'aqbk-');
        $zip=new ZipArchive();
        $zip->open($path,ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('database.sqlite',$tamper?'incorrect':'sqlite-data');
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.sqlite','files'=>[
                ['name'=>'database.sqlite','bytes'=>11,'sha256'=>hash('sha256','sqlite-data')]
            ],
        ]));
        $zip->close();
        return $path;
    }

    public function test_valid_manifest_matches_archive(): void
    {
        $path=$this->makeArchive(false);
        try { BackupArchiveValidator::validate($path);self::assertTrue(true); }
        finally {unlink($path);}
    }

    public function test_corrupted_entry_fails_integrity_check(): void
    {
        $path=$this->makeArchive(true);
        try {
            $this->expectException(RuntimeException::class);
            BackupArchiveValidator::validate($path);
        } finally {unlink($path);}
    }
}
