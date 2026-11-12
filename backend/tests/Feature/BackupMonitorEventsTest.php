<?php
namespace Tests\Feature;

use App\Support\BackupMonitorEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class BackupMonitorEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default','array');
        config()->set('backup.alert_email','ops@example.test');
        config()->set('backup.alert_enabled',true);
        config()->set('backup.alert_cooldown_minutes',360);
        config()->set('mail.default','smtp');
        Cache::flush();
        Mail::fake();
    }

    public function test_healthy_check_never_triggers_alert(): void
    {
        BackupMonitorEvents::record('fresh');
        Mail::assertNothingSent();
    }

    public function test_failed_check_alerts_once_during_cooldown(): void
    {
        BackupMonitorEvents::record('stale');
        BackupMonitorEvents::record('unknown');
        Mail::assertSentCount(1);
    }

    public function test_disabled_alerts_do_not_email(): void
    {
        config()->set('backup.alert_enabled',false);
        BackupMonitorEvents::record('stale');
        Mail::assertNothingSent();
    }

    public function test_log_or_array_mailer_must_not_pass_as_external_delivery(): void
    {
        config()->set('mail.default','log');
        BackupMonitorEvents::record('stale');
        Mail::assertNothingSent();
    }

    public function test_missing_email_fails_closed(): void
    {
        config()->set('backup.alert_email','not-an-address');
        BackupMonitorEvents::record('stale');
        Mail::assertNothingSent();
    }
}
