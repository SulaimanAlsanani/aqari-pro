<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StagingPreflightTest extends TestCase
{
    public function test_preflight_fails_closed_in_test_or_production_environment(): void
    {
        self::assertSame(1,Artisan::call('staging:preflight'));
        self::assertStringContainsString('APP_ENV must be staging',Artisan::output());
    }

    public function test_preflight_fails_when_staging_has_insecure_secrets_or_disks(): void
    {
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('app.debug',true);
        config()->set('app.url','http://localhost:8000');
        config()->set('database.default','sqlite');
        config()->set('filesystems.disks.backup',['driver'=>'local']);
        config()->set('backup.encryption_key','invalid');
        config()->set('document_security.scan_enabled',false);

        self::assertSame(1,Artisan::call('staging:preflight'));
        self::assertStringContainsString('APP_DEBUG must be false',Artisan::output());
        self::assertStringContainsString('Staging must use MySQL',Artisan::output());
        self::assertStringContainsString('Enable anti-malware scanning',Artisan::output());
    }

    public function test_hardened_staging_configuration_passes_without_network_requests(): void
    {
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('app.debug',false);
        config()->set('app.url','https://staging.example.test');
        config()->set('database.default','mysql');
        config()->set('database.connections.mysql.url',null);
        config()->set('database.connections.mysql.database','aqari_staging');
        config()->set('database.connections.mysql.host','db');
        config()->set('database.connections.mysql.port','3306');
        config()->set('database.connections.mysql.username','aqari_staging');
        config()->set('cache.default','file');
        config()->set('mail.default','smtp');
        config()->set('filesystems.default','local');
        config()->set('filesystems.disks.backup',[
            'driver'=>'s3','visibility'=>'private','bucket'=>'isolated-test-bucket',
        ]);
        config()->set('backup.encryption_key',base64_encode(random_bytes(32)));
        config()->set('document_security.scan_enabled',true);

        self::assertSame(0,Artisan::call('staging:preflight'));
        self::assertStringContainsString('configuration guards satisfied',Artisan::output());
    }
}
