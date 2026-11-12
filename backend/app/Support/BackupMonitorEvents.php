<?php
namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Restricted operational signals; never includes S3 paths, credentials,
 * encryption keys, or exception payloads in notifications.
 */
final class BackupMonitorEvents
{
    public static function record(string $state): void
    {
        if (!in_array($state, ['fresh','stale','unknown','invalid_configuration'], true)) {
            throw new \InvalidArgumentException('Unexpected backup monitor state.');
        }

        $context=['component'=>'backup_freshness','state'=>$state];
        if ($state==='fresh') {
            Log::info('Backup monitor check completed', $context);
            return;
        }

        Log::warning('Backup monitor requires attention', $context);
        if (!config('backup.alert_enabled',false)) return;

        $recipient=(string)config('backup.alert_email','');
        if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
            Log::error('Backup alert was enabled without a valid recipient.');
            return;
        }

        // Do not mistake local log/array mailers for delivered production alerts.
        if (in_array(config('mail.default'), ['log','array'],true)) {
            Log::error('Backup alert delivery not configured with an external mail transport.');
            return;
        }

        $cooldown=(int)config('backup.alert_cooldown_minutes',360);
        $cooldown=max(5,min(1440,$cooldown));
        $key='aqari:backup-alert:v1:'.hash('sha256',$recipient);
        try {
            // Use shared cache on multi-node deployments; suppress repeated alerts.
            if (!Cache::add($key, true, now()->addMinutes($cooldown))) return;
        } catch (Throwable $exception) {
            report($exception);
            Log::error('Backup alert cooldown store unavailable.');
            return;
        }

        try {
            Mail::raw(
                'Aqari Pro backup monitoring requires attention. Status: '.$state.
                '. Review the protected operational logs and verify the most recent encrypted backup.',
                static function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('Aqari Pro: backup monitoring alert');
                }
            );
            Log::info('Backup alert submitted to the configured mail transport.');
        } catch (Throwable $exception) {
            Cache::forget($key);
            report($exception);
            Log::error('Backup alert submission failed.');
        }
    }
}
