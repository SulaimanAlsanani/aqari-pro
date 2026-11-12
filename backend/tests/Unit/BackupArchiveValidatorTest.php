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

    public function test_mysql_dump_manifest_is_accepted(): void
    {
        $path=tempnam(sys_get_temp_dir(),'mysqlbk-');
        $dump="-- MySQL dump\\nCREATE TABLE example (id int);\\n";
        $zip=new ZipArchive();
        $zip->open($path,ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.mysql.sql',$dump);
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.mysql.sql','files'=>[
                ['name'=>'database.mysql.sql','bytes'=>strlen($dump),'sha256'=>hash('sha256',$dump)]
            ],
        ],JSON_THROW_ON_ERROR));
        $zip->close();
        try { BackupArchiveValidator::validate($path); self::assertTrue(true); }
        finally { unlink($path); }
    }

    public function test_manifest_rejects_mixed_database_formats(): void
    {
        $path=tempnam(sys_get_temp_dir(),'mixedbk-');
        $first='sqlite-data';$second='mysql-dump';
        $zip=new ZipArchive();
        $zip->open($path,ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sqlite',$first);
        $zip->addFromString('database.mysql.sql',$second);
        $zip->addFromString('manifest.json',json_encode([
            'version'=>1,'database'=>'database.sqlite','files'=>[
                ['name'=>'database.sqlite','bytes'=>strlen($first),'sha256'=>hash('sha256',$first)],
                ['name'=>'database.mysql.sql','bytes'=>strlen($second),'sha256'=>hash('sha256',$second)],
            ],
        ],JSON_THROW_ON_ERROR));
        $zip->close();
        try {
            $this->expectException(RuntimeException::class);
            BackupArchiveValidator::validate($path);
        } finally { unlink($path); }
    }
}
