<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Explicitly run remote staging probes only after the isolated environment
 * and read-only live checks have passed. Does not modify application data.
 */
final class StagingServicesCheck extends Command
{
    protected $signature = 'staging:check-services
        {--mail-to= : Optional explicit recipient for a real SMTP delivery probe}';
    protected $description = 'Verify staging MySQL, working ClamAV engine, S3 round trip and optional SMTP message';

    public function handle(): int
    {
        // This guard must precede every network operation. An incorrect
        // staging configuration could otherwise access a production service.
        if (!app()->environment('staging')) {
            $this->error('Service checks may only run in an isolated staging environment.');
            return self::FAILURE;
        }

        $recipient=$this->option('mail-to');
        if ($recipient!==null &&
            (!is_string($recipient) || strlen($recipient)>254 ||
             !filter_var($recipient,FILTER_VALIDATE_EMAIL))) {
            $this->error('Invalid staging test recipient.');
            return self::FAILURE;
        }

        if ($recipient!==null && config('mail.default')!=='smtp') {
            $this->error('SMTP mailer required for a real staging email check.');
            return self::FAILURE;
        }

        // Before remote S3 or SMTP actions, the config and actual MySQL
        // probe plus ClamAV signature check must pass.
        if ($this->call('staging:preflight',['--live'=>true])!==self::SUCCESS) {
            $this->error('Staging preflight failed; no storage or mail probe was attempted.');
            return self::FAILURE;
        }
        if ($this->call('staging:check-antivirus')!==self::SUCCESS) {
            $this->error('ClamAV functional probe failed; no S3 or SMTP request was attempted.');
            return self::FAILURE;
        }
        if ($this->call('backup:check-storage',['--disk'=>'backup'])!==self::SUCCESS) {
            $this->error('Private staging S3 probe failed; SMTP was not attempted.');
            return self::FAILURE;
        }

        if ($recipient!==null) {
            // Explicit opt-in: SMTP delivery produces a real email.
            try {
                Mail::raw(
                    'Aqari Pro isolated staging mail delivery test. No user or financial data is included.',
                    function ($message) use ($recipient): void {
                        $message->to($recipient)->subject('Aqari Pro staging delivery test');
                    }
                );
            } catch (Throwable $exception) {
                // SMTP errors may contain credentials; avoid console leakage.
                report($exception);
                $this->error('Staging SMTP probe failed; inspect protected server logs.');
                return self::FAILURE;
            }
            $this->info('Staging SMTP transport accepted the test message; inbox delivery is not guaranteed.');
        } else {
            $this->warn('SMTP delivery not checked. Re-run with --mail-to=<staging test inbox> to opt in.');
        }
        $this->info('Staging service probes completed; backup restore and browser tests remain required.');
        return self::SUCCESS;
    }
}
