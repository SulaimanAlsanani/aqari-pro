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
        $payload = random_bytes(64);
        $disk = Storage::disk($name);
        $written = false;

        try {
            if (!$disk->put($path, $payload, ['visibility' => 'private'])) {
                throw new \RuntimeException('Probe upload failed.');
            }
            $written = true;
            $read = $disk->get($path);
            if (!is_string($read) || !hash_equals(hash('sha256', $payload), hash('sha256', $read))) {
                throw new \RuntimeException('Probe integrity mismatch.');
            }
            $this->info('Backup storage write and read verified; checking cleanup.');
        } catch (Throwable $exception) {
            $this->error('Backup storage verification failed: '.$exception->getMessage());
            return self::FAILURE;
        } finally {
            // Removal must also succeed; handled by the check below.
            if ($written) {
                try {
                    if (!$disk->delete($path)) {
                        $this->error('Could not remove verification probe: '.$path);
                        $written = false;
                    }
                } catch (Throwable $exception) {
                    $this->error('Could not remove verification probe: '.$exception->getMessage());
                    $written = false;
                }
            }
        }

        if (!$written) {
            return self::FAILURE;
        }
        $this->info('Private backup storage probe completed and removed.');
        return self::SUCCESS;
    }
}
