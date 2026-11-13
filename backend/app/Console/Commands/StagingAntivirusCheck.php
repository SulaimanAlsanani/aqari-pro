<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Tests the actual malware scanner with a clean file and harmless EICAR
 * antivirus test pattern. Never touches owner documents or database rows.
 */
final class StagingAntivirusCheck extends Command
{
    protected $signature='staging:check-antivirus';
    protected $description='Verify staging ClamAV detects the standard harmless EICAR test pattern';

    public function handle(): int
    {
        // Never execute scan probes on an unapproved environment.
        if ($this->call('staging:preflight') !== self::SUCCESS) {
            $this->error('Staging configuration invalid; no antivirus probe was attempted.');
            return self::FAILURE;
        }

        $binary=(string)config('document_security.clamscan_binary','');
        if ($binary==='' || !is_file($binary) || !is_executable($binary)) {
            $this->error('Staging antivirus executable is not available.');
            return self::FAILURE;
        }

        $path=tempnam(sys_get_temp_dir(),'aqari-av-');
        if ($path===false) {
            $this->error('Unable to create isolated antivirus test file.');
            return self::FAILURE;
        }

        try {
            $clean='Aqari Pro isolated staging scanner readiness test; no customer information.';
            if (file_put_contents($path,$clean)!==strlen($clean) ||
                $this->scanExitCode($binary,$path)!==0) {
                $this->error('ClamAV did not successfully scan a clean file.');
                return self::FAILURE;
            }

            // Split the harmless standard test signature in source code.
            $eicar='X5O!P%@AP[4\PZX54(P^)7CC)7}'.'$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
            if (strlen($eicar)!==68 || file_put_contents($path,$eicar)!==68 ||
                $this->scanExitCode($binary,$path)!==1) {
                $this->error('ClamAV did not reject the standard harmless antivirus test pattern.');
                return self::FAILURE;
            }
        } catch (Throwable $error) {
            // Never leak scanner output or local paths in operator logs.
            $this->error('ClamAV staging probe failed; inspect protected host logs.');
            return self::FAILURE;
        } finally {
            @unlink($path);
        }

        $this->info('PASS: staging ClamAV accepts clean files and rejects the harmless EICAR pattern.');
        return self::SUCCESS;
    }

    private function scanExitCode(string $binary, string $path): int
    {
        $process=new Process([$binary,'--no-summary','--',$path]);
        $process->setTimeout(min(60,max(1,(int)config('document_security.scan_timeout_seconds',30))));
        $process->run();
        return $process->getExitCode() ?? 2;
    }
}
