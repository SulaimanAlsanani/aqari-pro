<?php
namespace App\Console\Commands;

use App\Support\BackupArchiveValidator;
use App\Support\BackupEnvelope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Non-destructive verification of an encrypted remote backup.
 * Never writes to the live database or private document storage.
 */
final class BackupVerify extends Command
{
    protected $signature = 'backup:verify
        {object : Exact snapshots/YYYY/MM/DD/...aqbk object key}
        {--disk=backup : Private backup disk}';
    protected $description = 'Download, authenticate and inspect an encrypted snapshot without restoring it';

    public function handle(): int
    {
        $object=(string)$this->argument('object');
        $diskName=(string)$this->option('disk');
        if (!preg_match('~^snapshots/[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]{6}-[a-f0-9-]{36}\.aqbk$~D',$object)) {
            $this->error('Invalid snapshot object name.');
            return self::FAILURE;
        }
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
            $this->error('PHP zip extension required.');
            return self::FAILURE;
        }
        $directory=storage_path('app/backup-verify/'.Str::uuid());
        $archive=$directory.'/snapshot.zip';
        try {
            $disk=Storage::disk($diskName);
            // Bound download and decryption memory; the writer caps archive at 128 MiB.
            $size=$disk->size($object);
            if (!is_int($size) || $size<34 || $size>128*1024*1024+34) {
                throw new RuntimeException('Unexpected backup object size.');
            }
            $encrypted=$disk->get($object);
            if (!is_string($encrypted) || strlen($encrypted)!==$size) {
                throw new RuntimeException('Incomplete backup download.');
            }
            $plain=BackupEnvelope::decrypt($encrypted,$key);
            unset($encrypted);
            if (strlen($plain)>128*1024*1024) throw new RuntimeException('Archive limit exceeded.');
            if (!is_dir(dirname($directory)) && !mkdir(dirname($directory),0700,true)) {
                throw new RuntimeException('Could not create private verification directory.');
            }
            if (!mkdir($directory,0700,true)) throw new RuntimeException('Could not create verification workspace.');
            if (file_put_contents($archive,$plain,LOCK_EX)!==strlen($plain)) {
                throw new RuntimeException('Archive staging failed.');
            }
            unset($plain);
            BackupArchiveValidator::validate($archive);
            $this->info('PASS: encrypted backup authenticated; complete archive manifest and file hashes verified.');
            $this->line('Object: '.$object);
            $this->warn('This is NOT a restore drill and does not validate the SQLite schema or application startup.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('FAIL: backup verification failed; review protected server logs.');
            return self::FAILURE;
        } finally {
            if (is_file($archive)) @unlink($archive);
            if (is_dir($directory)) @rmdir($directory);
        }
    }
}
