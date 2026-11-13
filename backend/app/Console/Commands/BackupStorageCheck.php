<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifies connectivity and permissions on the configured private backup disk.
 * This is NOT a database backup or a restore test.
 */
class BackupStorageCheck extends Command
{
    protected $signature = 'backup:check-storage {--disk=backup : Filesystem disk to verify}';
    protected $description = 'Write, read, verify and remove a random probe on private backup storage';

    public function handle(): int
    {
        $name = (string) $this->option('disk');
        $disks = config('filesystems.disks', []);
        if (!isset($disks[$name]) || !is_array($disks[$name])) {
            $this->error('Backup storage disk is not configured.');
            return self::FAILURE;
        }

        $config = $disks[$name];
        if (($config['driver'] ?? null) !== 's3') {
            $this->error('Backup preflight requires an S3-compatible private disk, not local or public storage.');
            return self::FAILURE;
        }
        if (($config['visibility'] ?? 'private') !== 'private') {
            $this->error('Backup storage must be private.');
            return self::FAILURE;
        }

        $path = 'healthchecks/'.Str::uuid().'.bin';
        $success = false;
        $cleanupOk = true;
        $disk = null;

        try {
            // Disk creation may itself fail (bad adapter, credentials, invalid
            // endpoint). Include it in the guarded block.
            $disk = Storage::disk($name);
            $payload = random_bytes(64);
            if (!$disk->put($path, $payload, ['visibility' => 'private'])) {
                throw new \RuntimeException('Probe upload failed.');
            }
            $read = $disk->get($path);
            if (!is_string($read) || !hash_equals(
                hash('sha256', $payload), hash('sha256', $read)
            )) {
                throw new \RuntimeException('Probe integrity mismatch.');
            }
            $success = true;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Backup storage verification failed; inspect protected server logs.');
        } finally {
            if ($disk !== null) {
                // A remote upload may leave a partial object even on failure.
                try {
                    if (!$disk->delete($path) || $disk->exists($path)) {
                        // Some S3-compatible adapters report a successful
                        // delete even when the object remains available.
                        $this->error('Could not verify removal of the backup probe.');
                        $cleanupOk = false;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $this->error('Could not remove the backup verification probe.');
                    $cleanupOk = false;
                }
            }
        }

        if (!$success || !$cleanupOk) {
            return self::FAILURE;
        }
        $this->info('Private backup storage probe completed and removed.');
        return self::SUCCESS;
    }
}
