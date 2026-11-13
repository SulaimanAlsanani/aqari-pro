<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StagingServicesCheckTest extends TestCase
{
    public function test_service_probes_refuse_non_staging_environments(): void
    {
        Storage::shouldReceive('disk')->never();
        Mail::fake();

        self::assertSame(1,Artisan::call('staging:check-services'));
        self::assertStringContainsString('only run in an isolated staging environment',Artisan::output());
        Mail::assertNothingSent();
    }

    public function test_invalid_recipient_fails_before_remote_services(): void
    {
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        Storage::shouldReceive('disk')->never();
        Mail::fake();

        self::assertSame(1,Artisan::call('staging:check-services',['--mail-to'=>'not-an-email']));
        self::assertStringContainsString('Invalid staging test recipient',Artisan::output());
        Mail::assertNothingSent();
    }

    public function test_wrong_mail_transport_fails_before_s3_probe(): void
    {
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('mail.default','log');
        Storage::shouldReceive('disk')->never();
        Mail::fake();

        self::assertSame(1,Artisan::call('staging:check-services',['--mail-to'=>'test@example.test']));
        self::assertStringContainsString('SMTP mailer required',Artisan::output());
        Mail::assertNothingSent();
    }

    public function test_invalid_preflight_blocks_s3_and_mail_attempts(): void
    {
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('app.debug',true);
        config()->set('mail.default','smtp');
        Storage::shouldReceive('disk')->never();
        Mail::fake();

        self::assertSame(1,Artisan::call('staging:check-services',['--mail-to'=>'test@example.test']));
        self::assertStringContainsString('Staging preflight failed',Artisan::output());
        Mail::assertNothingSent();
    }
}
