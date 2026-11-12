<?php
namespace App\Console\Commands;

use App\Support\BackupArchiveValidator;
use App\Support\BackupEnvelope;
use App\Support\BackupStagedFilesVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Stages a verified snapshot outside live application storage.
 * Never replaces the active database or attachments. MySQL dumps are staged,
 * not executed against a server.
 */
final class BackupStageRestore extends Command
{
    protected $signature = 'backup:stage-restore
        {object : Exact snapshots/YYYY/MM/DD/...aqbk key}
        {--disk=backup : Private backup disk}';
    protected $description = 'Extract a verified encrypted snapshot into an isolated private staging directory';

    public function handle(): int
    {
        $object=(string)$this->argument('object');
        if (!preg_match('~^snapshots/[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]{6}-[a-f0-9-]{36}\.aqbk$~D',$object)) {
            $this->error('Invalid snapshot object key.');
            return self::FAILURE;
        }
        $diskName=(string)$this->option('disk');
        $config=config('filesystems.disks.'.$diskName);
        if (!is_array($config) || ($config['driver']??null)!=='s3'
            || ($config['visibility']??null)!=='private') {
            $this->error('A private S3 backup disk is required.');
            return self::FAILURE;
        }
        $key=(string)config('backup.encryption_key');
        if (strlen(base64_decode($key,true) ?: '')!==32) {
            $this->error('Invalid backup encryption key.');
            return self::FAILURE;
        }
        if (!class_exists(ZipArchive::class)) {
            $this->error('PHP zip extension is required.');
            return self::FAILURE;
        }

        $root=storage_path('app/restore-staging');
        $dir=$root.'/'.Str::uuid();
        $zipPath=$dir.'/snapshot.zip';
        $success=false;
        try {
            $disk=Storage::disk($diskName);
            $size=$disk->size($object);
            if (!is_int($size) || $size<34 || $size>128*1024*1024+34) {
                throw new RuntimeException('Unexpected object size.');
            }
            $encrypted=$disk->get($object);
            if (!is_string($encrypted) || strlen($encrypted)!==$size) {
                throw new RuntimeException('Incomplete backup download.');
            }
            $plain=BackupEnvelope::decrypt($encrypted,$key);
            unset($encrypted);
            if (strlen($plain)>128*1024*1024) throw new RuntimeException('Archive exceeds size cap.');
            if (!is_dir($root) && !mkdir($root,0700,true)) throw new RuntimeException('Could not create staging root.');
            if (!mkdir($dir,0700,true)) throw new RuntimeException('Could not create staging folder.');
            if (file_put_contents($zipPath,$plain,LOCK_EX)!==strlen($plain)) {
                throw new RuntimeException('Could not stage archive.');
            }
            unset($plain);
            BackupArchiveValidator::validate($zipPath);

            $zip=new ZipArchive();
            if ($zip->open($zipPath)!==true) throw new RuntimeException('Invalid archive.');
            try {
                for ($i=0;$i<$zip->numFiles;$i++) {
                    $name=$zip->getNameIndex($i);
                    if ($name==='manifest.json') continue;
                    // Paths are already allowlisted by the validator; never call extractTo().
                    if (!in_array($name,['database.sqlite','database.mysql.sql'],true) &&
                        !preg_match('~^attachments/owners/[0-9]+/documents/[A-Za-z0-9_./-]+$~D',$name)) {
                        throw new RuntimeException('Unexpected restored path.');
                    }
                    $target=$dir.'/'.$name;
                    $parent=dirname($target);
                    if (!is_dir($parent) && !mkdir($parent,0700,true)) {
                        throw new RuntimeException('Cannot create restored folder.');
                    }
                    $source=$zip->getStream($name);
                    if (!is_resource($source)) throw new RuntimeException('Cannot read archive member.');
                    $output=fopen($target,'xb');
                    if (!$output) { fclose($source); throw new RuntimeException('Cannot create restored member.'); }
                    try {
                        if (stream_copy_to_stream($source,$output)===false) {
                            throw new RuntimeException('Restored member copy failed.');
                        }
                    } finally { fclose($source); fclose($output); }
                    chmod($target,0600);
                }
            } finally { $zip->close(); }
            BackupStagedFilesVerifier::verify($zipPath,$dir);
            unlink($zipPath);

            if (is_file($dir.'/database.sqlite')) {
                if (!class_exists(\SQLite3::class)) {
                    throw new RuntimeException('SQLite3 extension required to validate SQLite snapshot.');
                }
                $db=new \SQLite3($dir.'/database.sqlite',SQLITE3_OPEN_READONLY);
                try {
                    $result=$db->querySingle('PRAGMA integrity_check');
                    if ($result!=='ok') throw new RuntimeException('Restored SQLite integrity check failed.');
                } finally { $db->close(); }
                $message='Isolated restore staged and SQLite integrity validated: ';
            } elseif (is_file($dir.'/database.mysql.sql')) {
                // SQL is never interpreted here. The dump contains its own
                // CREATE DATABASE / USE commands, so automated execution
                // could overwrite an unintended target.
                $message='Isolated MySQL SQL dump staged (NOT imported): ';
            } else {
                throw new RuntimeException('No database payload in archive.');
            }
            $success=true;
            $this->info($message.$dir);
            $this->warn('Live database and attachments were NOT modified. Do not serve staging folder publicly.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Restore staging failed; inspect protected server logs.');
            return self::FAILURE;
        } finally {
            if (!$success && is_dir($dir)) $this->removeTree($dir);
        }
    }

    private function removeTree(string $directory): void
    {
        $iterator=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
