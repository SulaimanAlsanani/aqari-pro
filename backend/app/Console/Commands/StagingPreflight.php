<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only configuration gate. Does not connect to production services,
 * create database tables, alter secrets, or execute backup/restore.
 */
final class StagingPreflight extends Command
{
    protected $signature = 'staging:preflight';
    protected $description = 'Validate Aqari Pro isolated staging configuration before a recovery rehearsal';

    public function handle(): int
    {
        $failures=[];
        $check=static function (bool $ok,string $label) use (&$failures): void {
            if (!$ok) $failures[]=$label;
        };

        $check(app()->environment('staging'), 'APP_ENV must be staging');
        $check(config('app.debug')===false, 'APP_DEBUG must be false');
        $url=(string) config('app.url');
        $parts=parse_url($url);
        $check(
            is_array($parts) && ($parts['scheme']??'')==='https'
            && !empty($parts['host'])
            && !in_array(strtolower((string)($parts['host']??'')),['localhost','127.0.0.1'],true)
            && !isset($parts['user']) && !isset($parts['pass']),
            'APP_URL must be a non-local HTTPS URL without embedded credentials'
        );

        $check(config('database.default')==='mysql', 'Staging must use MySQL');
        $mysql=config('database.connections.mysql');
        $check(is_array($mysql) && empty($mysql['url']), 'MySQL connection must not use DB_URL');
        $database=(string)($mysql['database']??'');
        $check(
            (bool) preg_match('/^[a-zA-Z0-9_]+$/D',$database)
            && preg_match('/(^|_)stag(e|ing)($|_)/i',$database)===1,
            'MySQL database name must explicitly contain staging'
        );
        $check(
            !in_array(config('queue.default'),['sync',null],true),
            'Use a real staging queue driver (not sync)'
        );
        $check(
            !in_array(config('cache.default'),['array',null],true),
            'Use a persistent staging cache driver'
        );
        $check(
            !in_array(config('mail.default'),['log','array',null],true),
            'Use a configured outbound mail transport'
        );

        $check(config('filesystems.default')==='local', 'Private documents must use a local private disk');
        $disk=config('filesystems.disks.backup');
        $check(
            is_array($disk) && ($disk['driver']??'')==='s3'
            && ($disk['visibility']??null)==='private'
            && !empty($disk['bucket']),
            'Encrypted backups require a private S3 bucket'
        );
        $key=(string)config('backup.encryption_key');
        $check(strlen(base64_decode($key,true)?:'')===32, 'Set independent 32-byte BACKUP_ENCRYPTION_KEY');

        $check(config('documents.scan_enabled')===true, 'Enable anti-malware scanning for private documents');

        if ($failures) {
            foreach ($failures as $failure) $this->error('FAIL: '.$failure);
            $this->error('Staging preflight failed: '.count($failures).' configuration guard(s).');
            return self::FAILURE;
        }
        $this->info('PASS: staging configuration guards satisfied.');
        $this->warn('This does NOT verify real S3, SMTP, malware engine, database connectivity or restore readiness.');
        return self::SUCCESS;
    }
}
