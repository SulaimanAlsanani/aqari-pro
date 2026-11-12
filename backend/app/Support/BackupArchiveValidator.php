<?php
namespace App\Support;

use RuntimeException;
use ZipArchive;

final class BackupArchiveValidator
{
    public static function validate(string $archive): void
    {
        $zip=new ZipArchive();
        if ($zip->open($archive)!==true) throw new RuntimeException('Backup archive could not be opened.');
        try {
            $raw=$zip->getFromName('manifest.json');
            if (!is_string($raw) || strlen($raw)>1024*1024) throw new RuntimeException('Missing or invalid backup manifest.');
            $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if (($manifest['version']??null)!==1 || !is_array($manifest['files']??null)
                || !in_array($manifest['database']??null,['database.sqlite','database.mysql.sql'],true)) {
                throw new RuntimeException('Unknown backup manifest version.');
            }
            $databaseName=$manifest['database'];
            $expected=['manifest.json'=>true];
            $totalSize=0;
            if (count($manifest['files'])>20000) throw new RuntimeException('Too many backup files.');
            foreach($manifest['files'] as $entry){
                if (!is_array($entry)) throw new RuntimeException('Invalid manifest entry.');
                $name=$entry['name']??'';
                if (!is_string($name) || !preg_match('~^(database\.sqlite|database\.mysql\.sql|attachments/owners/[0-9]+/documents/[A-Za-z0-9_./-]+)$~D',$name)
                    || str_contains($name,'..') || isset($expected[$name])) {
                    throw new RuntimeException('Invalid or duplicated archived path.');
                }
                if (!is_int($entry['bytes']??null) || $entry['bytes']<0 ||
                    !preg_match('/^[a-f0-9]{64}$/D',(string)($entry['sha256']??''))) {
                    throw new RuntimeException('Invalid manifest checksum.');
                }
                $index=$zip->locateName($name);
                if ($index===false) throw new RuntimeException('Backup archive is missing a manifest entry.');
                $stat=$zip->statIndex($index);
                $totalSize += $entry['bytes'];
                if ($totalSize>128*1024*1024) throw new RuntimeException('Backup expands beyond 128 MiB.');
                if (($stat['size']??-1)!==$entry['bytes']) throw new RuntimeException('Backup item size mismatch.');
                $stream=$zip->getStream($name);
                if (!$stream) throw new RuntimeException('Could not inspect backup item.');
                $hash=hash_init('sha256');
                $readBytes=hash_update_stream($hash,$stream, $entry['bytes']+1);
                fclose($stream);
                if ($readBytes!==$entry['bytes']) throw new RuntimeException('Backup item size changed during verification.');
                if (!hash_equals($entry['sha256'],hash_final($hash))) {
                    throw new RuntimeException('Backup item digest mismatch.');
                }
                $expected[$name]=true;
            }
            if (!isset($expected[$databaseName]) || (isset($expected['database.sqlite']) && isset($expected['database.mysql.sql'])) || count($expected)!==$zip->numFiles) {
                throw new RuntimeException('Backup manifest does not cover all archive members.');
            }
        } finally {
            $zip->close();
        }
    }
}
