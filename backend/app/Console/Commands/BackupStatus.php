<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\BackupMonitorEvents;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only freshness monitor for private encrypted backup objects.
 * This checks recency and object presence, NOT decryptability or restore readiness.
 */
final class BackupStatus extends Command
{
    protected $signature = 'backup:status {--disk=backup : Private backup disk} {--max-age-hours=26 : Oldest allowed snapshot age}';
    protected $description = 'Fail if no recent encrypted snapshot exists on configured private S3 storage';

    public function handle(): int
    {
        $diskName=(string)$this->option('disk');
        $hours=filter_var($this->option('max-age-hours'),FILTER_VALIDATE_INT);
        if ($hours===false || $hours<1 || $hours>8760) {
            $this->error('Snapshot maximum age must be an integer between 1 and 8760 hours.');
            BackupMonitorEvents::record('invalid_configuration');
            return self::FAILURE;
        }
        $config=config('filesystems.disks.'.$diskName);
        if (!is_array($config) || ($config['driver']??null)!=='s3' ||
            ($config['visibility']??null)!=='private') {
            $this->error('A private S3-compatible backup disk is required.');
            BackupMonitorEvents::record('invalid_configuration');
            return self::FAILURE;
        }

        try {
            $disk=Storage::disk($diskName);
            // Query only recent day partitions rather than listing an entire bucket.
            $now=now('UTC');
            $earliest=$now->copy()->subHours($hours);
            $prefixes=[];
            for ($day=$earliest->copy()->startOfDay();$day->lte($now);$day->addDay()) {
                $prefixes[]='snapshots/'.$day->format('Y/m/d');
                if (count($prefixes)>367) throw new \RuntimeException('Snapshot date range exceeds limit.');
            }

            $latest=null;
            $latestKey=null;
            foreach ($prefixes as $prefix) {
                foreach ($disk->files($prefix) as $path) {
                    if (!preg_match(
                        '~^snapshots/([0-9]{4})/([0-9]{2})/([0-9]{2})/([0-9]{6})-[a-f0-9-]{36}\.aqbk$~D',
                        $path,$matches
                    )) continue;
                    // Only signed-integer object creation timestamp reported by storage is used for freshness.
                    $modified=$disk->lastModified($path);
                    $size=$disk->size($path);
                    if (!is_int($modified) || !is_int($size) || $size<34 ||
                        $size>128*1024*1024+34 || $modified>$now->timestamp+300) continue;
                    if ($latest===null || $modified>$latest) {
                        $latest=$modified;
                        $latestKey=$path;
                    }
                }
            }

            if ($latest===null || $latest<$earliest->timestamp) {
                $this->error('BACKUP STALE: no recent encrypted snapshot was found.');
                BackupMonitorEvents::record('stale');
                return self::FAILURE;
            }
            BackupMonitorEvents::record('fresh');
            $ageMinutes=intdiv(max(0,$now->timestamp-$latest),60);
            $this->info('BACKUP FRESH: newest private snapshot is '.$ageMinutes.' minutes old.');
            $this->line('Object: '.$latestKey);
            $this->warn('Freshness is not proof of integrity. Run backup:verify and isolated restore drills separately.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('BACKUP STATUS UNKNOWN: storage inspection failed; inspect protected logs.');
            BackupMonitorEvents::record('unknown');
            return self::FAILURE;
        }
    }
}
