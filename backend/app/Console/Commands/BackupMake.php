<?php
namespace App\Console\Commands;

use App\Support\BackupEnvelope;
use App\Support\BackupArchiveValidator;
use App\Support\MySqlSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Offline small-installation SQLite or MySQL snapshot with private attachments.
 * Requires maintenance mode to avoid writes during the snapshot.
 * Requires all external writers stopped. MySQL dump is transactionally consistent
 * only for InnoDB tables; storage files still require an offline write barrier.
 */
final class BackupMake extends Command
{
    protected $signature = 'backup:make {--disk=backup : Private object storage disk}';
    protected $description = 'Upload a verified encrypted SQLite/MySQL database and attachment snapshot';

    public function handle(): int
    {
        if (!app()->isDownForMaintenance()) {
            $this->error('Enter maintenance mode before creating a consistent SQLite snapshot.');
            return self::FAILURE;
        }
        $driver=DB::getDefaultConnection();
        if (!in_array($driver,['sqlite','mysql'],true)) {
            $this->error('Unsupported database driver for offline snapshots.');
            return self::FAILURE;
        }
        $diskName = (string) $this->option('disk');
        $config = config('filesystems.disks.'.$diskName);
        if (!is_array($config) || ($config['driver'] ?? '') !== 's3' ||
            ($config['visibility'] ?? 'private') !== 'private') {
            $this->error('A private S3-compatible backup disk is required.');
            return self::FAILURE;
        }
        $key = (string) config('backup.encryption_key');
        if (strlen(base64_decode($key, true) ?: '') !== 32) {
            $this->error('Configure a dedicated 32-byte BACKUP_ENCRYPTION_KEY.');
            return self::FAILURE;
        }
        if (!class_exists(ZipArchive::class)) {
            $this->error('PHP zip extension is required.');
            return self::FAILURE;
        }

        $dir = storage_path('app/backup-work/'.Str::uuid());
        $zipPath = $dir.'/snapshot.zip';
        $databaseEntry=$driver==='mysql'?'database.mysql.sql':'database.sqlite';
        $dbCopy = $dir.'/'.$databaseEntry;
        $object = 'snapshots/'.now('UTC')->format('Y/m/d/His').'-'.Str::uuid().'.aqbk';
        $max = 128 * 1024 * 1024;
        $remoteWritten = false;

        try {
            if (!is_dir(dirname($dir)) && !mkdir(dirname($dir),0700,true)) throw new RuntimeException('Could not create backup directory.');
            if (!mkdir($dir, 0700, true)) throw new RuntimeException('Could not create private workspace.');
            if ($driver==='sqlite') {
                $source = DB::connection('sqlite')->getDatabaseName();
                if (!$source || !is_file($source)) throw new RuntimeException('SQLite database file is missing.');
                DB::connection('sqlite')->getPdo()->exec('VACUUM INTO '.DB::connection('sqlite')->getPdo()->quote($dbCopy));
            } else {
                MySqlSnapshot::export($dbCopy,$dir);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open archive.');
            }
            try {
                $manifest = ['version'=>1,'created_at'=>now('UTC')->toIso8601String(),
                    'database'=>$databaseEntry,'files'=>[]];
                $size = filesize($dbCopy);
                if ($size === false || $size > $max) throw new RuntimeException('Database exceeds small-installation limit.');
                if (!$zip->addFile($dbCopy, $databaseEntry)) throw new RuntimeException('Could not archive database.');
                $manifest['files'][]=['name'=>$databaseEntry,'bytes'=>$size,'sha256'=>hash_file('sha256',$dbCopy)];
                $disk = Storage::disk('local');
                foreach ($disk->allFiles('owners') as $path) {
                    // Reject unexpected filenames and links; include only private owner documents.
                    if (!preg_match('~^owners/[0-9]+/documents/[a-zA-Z0-9_./-]+$~D',$path) ||
                        str_contains($path,'..')) {
                        throw new RuntimeException('Unexpected attachment path.');
                    }
                    $stream = $disk->readStream($path);
                    if (!is_resource($stream)) throw new RuntimeException('Attachment not readable.');
                    $data = stream_get_contents($stream, $max - $size + 1);
                    fclose($stream);
                    if (!is_string($data) || strlen($data) > $max - $size) {
                        throw new RuntimeException('Attachments exceed small-installation limit.');
                    }
                    $size += strlen($data);
                    $entry='attachments/'.$path;
                    if (!$zip->addFromString($entry, $data)) throw new RuntimeException('Could not archive attachment.');
                    $manifest['files'][]=['name'=>$entry,'bytes'=>strlen($data),'sha256'=>hash('sha256',$data)];
                }
                $manifestJson=json_encode($manifest,JSON_THROW_ON_ERROR);
                if (!$zip->addFromString('manifest.json',$manifestJson)) throw new RuntimeException('Could not archive manifest.');
            } finally {
                $zip->close();
            }
            if (filesize($zipPath) > $max) throw new RuntimeException('Archive exceeds size limit.');
            BackupArchiveValidator::validate($zipPath);
            $plaintext=file_get_contents($zipPath);
            if ($plaintext===false) throw new RuntimeException('Archive read failed.');
            $encrypted=BackupEnvelope::encrypt($plaintext,$key);
            unset($plaintext);
            $remote=Storage::disk($diskName);
            $remoteWritten = true; // Cleanup even if remote partially writes then fails.
            if (!$remote->put($object,$encrypted,['visibility'=>'private'])) {
                throw new RuntimeException('Backup upload failed.');
            }
            $downloaded=$remote->get($object);
            if (!is_string($downloaded) || !hash_equals(hash('sha256',$encrypted),hash('sha256',$downloaded))) {
                throw new RuntimeException('Remote backup integrity verification failed.');
            }
            if (!hash_equals(hash('sha256',BackupEnvelope::decrypt($downloaded,$key)),hash_file('sha256',$zipPath))) {
                throw new RuntimeException('Backup decryption round-trip failed.');
            }
            $this->info('Encrypted snapshot uploaded and transport integrity verified: '.$object);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            if ($remoteWritten) {
                try { Storage::disk($diskName)->delete($object); }
                catch (Throwable $cleanupError) { report($cleanupError); }
            }
            $this->error('Backup failed; inspect protected server logs.');
            return self::FAILURE;
        } finally {
            foreach ([$zipPath,$dbCopy] as $file) if (is_file($file)) @unlink($file);
            if (is_dir($dir)) @rmdir($dir);
        }
    }
}
