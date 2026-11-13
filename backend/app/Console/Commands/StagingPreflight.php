<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\ClamAvSignatureHealth;
use Throwable;

/**
 * Read-only configuration gate. Does not connect to production services,
 * create database tables, alter secrets, or execute backup/restore.
 */
final class StagingPreflight extends Command
{
    protected $signature = 'staging:preflight {--live : Check actual MySQL connectivity and ClamAV signatures without writing data}';
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
            ($mysql['host']??null)==='db' && (string)($mysql['port']??'')==='3306',
            'Staging MySQL must use its internal Compose db service'
        );
        $check(
            ($mysql['username']??null)==='aqari_staging',
            'Staging must use the dedicated aqari_staging DB account'
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
        // Keep staging storage names visibly distinct to reduce the chance
        // of testing against a production backup bucket.
        $bucket=(string)($disk['bucket']??'');
        $check(
            preg_match('/(^|[-_.])stag(e|ing)([-_.]|$)/i',$bucket)===1,
            'Staging S3 bucket name must explicitly identify staging'
        );
        $key=(string)config('backup.encryption_key');
        $check(strlen(base64_decode($key,true)?:'')===32, 'Set independent 32-byte BACKUP_ENCRYPTION_KEY');

        $check(config('document_security.scan_enabled')===true, 'Enable anti-malware scanning for private documents');

        // Live checks are opt-in. Never connect to databases after a failed
        // configuration guard: the configured endpoint could be production.
        if (!$failures && $this->option('live')) {
            try {
                $result=DB::connection('mysql')->select('SELECT 1 AS ready');
                $check(count($result)===1 && (int)($result[0]->ready??0)===1,
                    'Staging MySQL read-only probe returned an unexpected result');
            } catch (Throwable $exception) {
                // Do not expose connection strings, secrets or SQL driver details.
                $check(false,'Staging MySQL read-only probe failed');
            }
            $scanner=(string)config('document_security.clamscan_binary','');
            $check(
                $scanner!=='' && is_file($scanner) && is_executable($scanner),
                'ClamAV executable is not available'
            );
            $signatures=ClamAvSignatureHealth::inspect(
                (string)config('document_security.signature_dir','/var/lib/clamav'),
                time(),
                (int)config('document_security.signature_max_age_hours',168)
            );
            $check($signatures['main'] && $signatures['daily'],
                'Nonempty ClamAV main and daily signature databases are required');
            $check($signatures['daily_fresh'],
                'ClamAV daily signatures are missing or older than the configured threshold');
        }

        if ($failures) {
            foreach ($failures as $failure) $this->error('FAIL: '.$failure);
            $this->error('Staging preflight failed: '.count($failures).' configuration guard(s).');
            return self::FAILURE;
        }
        $this->info('PASS: staging configuration guards satisfied.');
        $this->warn($this->option('live')
            ? 'This does NOT verify S3, SMTP delivery, an actual malware scan, restore or HTTPS reachability.'
            : 'Configuration only; run staging:preflight --live to check MySQL connectivity and ClamAV files.');
        return self::SUCCESS;
    }
}
