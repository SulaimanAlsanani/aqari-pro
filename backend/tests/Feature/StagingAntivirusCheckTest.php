<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StagingAntivirusCheckTest extends TestCase
{
    private ?string $fakeScanner=null;

    protected function tearDown(): void
    {
        if ($this->fakeScanner!==null) @unlink($this->fakeScanner);
        parent::tearDown();
    }

    private function configureSafeStaging(): void
    {
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('app.debug',false);
        config()->set('app.url','https://staging.example.test');
        config()->set('database.default','mysql');
        config()->set('database.connections.mysql.url',null);
        config()->set('database.connections.mysql.host','db');
        config()->set('database.connections.mysql.port','3306');
        config()->set('database.connections.mysql.database','aqari_staging');
        config()->set('database.connections.mysql.username','aqari_staging');
        config()->set('cache.default','file');
        config()->set('mail.default','smtp');
        config()->set('filesystems.default','local');
        config()->set('filesystems.disks.backup',[
            'driver'=>'s3','visibility'=>'private','bucket'=>'aqari-staging-test-bucket',
        ]);
        config()->set('backup.encryption_key',base64_encode(random_bytes(32)));
        config()->set('document_security.scan_enabled',true);
    }

    private function fakeScanner(bool $detect): void
    {
        $path=tempnam(sys_get_temp_dir(),'aqari-fake-scanner-');
        self::assertNotFalse($path);
        // The last argument to the scanner is the test file. This stub
        // emulates a scanner, without requiring ClamAV in CI.
        $source=$detect
            ? "#!/bin/sh\nif grep -q 'EICAR' \"\$3\"; then exit 1; fi\nexit 0\n"
            : "#!/bin/sh\nexit 0\n";
        file_put_contents($path,$source);
        chmod($path,0700);
        $this->fakeScanner=$path;
        config()->set('document_security.clamscan_binary',$path);
    }

    public function test_unsafe_configuration_cannot_execute_a_scanner_process(): void
    {
        $path=tempnam(sys_get_temp_dir(),'aqari-never-invoke-');
        self::assertNotFalse($path);
        $this->fakeScanner=$path;
        file_put_contents($path,"#!/bin/sh\nexit 0\n");
        chmod($path,0700);
        config()->set('document_security.clamscan_binary',$path);
        DB::shouldReceive('connection')->never();

        self::assertSame(1,Artisan::call('staging:check-antivirus'));
        self::assertStringContainsString('Staging configuration invalid',Artisan::output());
    }

    public function test_functional_scanner_detects_harmless_eicar_fixture(): void
    {
        $this->configureSafeStaging();
        $this->fakeScanner(true);
        DB::shouldReceive('connection')->never();

        self::assertSame(0,Artisan::call('staging:check-antivirus'));
        self::assertStringContainsString('PASS: staging ClamAV',Artisan::output());
    }

    public function test_scanner_that_never_detects_signatures_fails_closed(): void
    {
        $this->configureSafeStaging();
        $this->fakeScanner(false);
        DB::shouldReceive('connection')->never();

        self::assertSame(1,Artisan::call('staging:check-antivirus'));
        self::assertStringContainsString('did not reject',Artisan::output());
    }
}
