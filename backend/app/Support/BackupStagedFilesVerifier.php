<?php
namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Confirm every staged file matches the authenticated manifest after extraction.
 * No paths outside the dedicated staging folder are read.
 */
final class BackupStagedFilesVerifier
{
    public static function verify(string $archive, string $directory): int
    {
        $zip=new ZipArchive();
        if ($zip->open($archive)!==true) throw new RuntimeException('Could not inspect staged archive.');
        try {
            $raw=$zip->getFromName('manifest.json');
            if (!is_string($raw)) throw new RuntimeException('Backup manifest missing.');
            $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($manifest['files']??null)) throw new RuntimeException('Invalid backup manifest.');
            $count=0;
            foreach ($manifest['files'] as $entry) {
                $name=$entry['name']??null;
                if (!is_string($name) || !preg_match(
                    '~^(database\.sqlite|database\.mysql\.sql|attachments/owners/[0-9]+/documents/[A-Za-z0-9_./-]+)$~D',
                    $name
                ) || str_contains($name,'..')) {
                    throw new RuntimeException('Unexpected staged file path.');
                }
                $path=$directory.'/'.$name;
                if (!is_file($path) || is_link($path) || !is_int($entry['bytes']??null) ||
                    filesize($path)!==$entry['bytes'] ||
                    !is_string($entry['sha256']??null) ||
                    !hash_equals($entry['sha256'],hash_file('sha256',$path))) {
                    throw new RuntimeException('Staged file does not match the backup manifest.');
                }
                $count++;
            }
            if ($count<1) throw new RuntimeException('No staged files to inspect.');
            return $count;
        } finally {
            $zip->close();
        }
    }
}
