<?php
namespace Tests\Unit;

use App\Support\ClamAvSignatureHealth;
use PHPUnit\Framework\TestCase;

final class ClamAvSignatureHealthTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder=sys_get_temp_dir().'/aqari-clamav-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->folder,0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder.'/*') ?: [] as $file) @unlink($file);
        @rmdir($this->folder);
        parent::tearDown();
    }

    private function fixture(string $name, int $modified, string $content='signatures'): void
    {
        $file=$this->folder.'/'.$name;
        file_put_contents($file,$content);
        touch($file,$modified);
        clearstatcache(true,$file);
    }

    public function test_fresh_main_and_daily_signatures_are_accepted(): void
    {
        $now=1800000000;
        $this->fixture('main.cvd',$now-3600);
        $this->fixture('daily.cld',$now-3600);
        self::assertSame(
            ['main'=>true,'daily'=>true,'daily_fresh'=>true],
            ClamAvSignatureHealth::inspect($this->folder,$now)
        );
    }

    public function test_missing_empty_and_expired_signatures_fail_closed(): void
    {
        $now=1800000000;
        self::assertSame(
            ['main'=>false,'daily'=>false,'daily_fresh'=>false],
            ClamAvSignatureHealth::inspect($this->folder,$now)
        );
        $this->fixture('main.cvd',$now,'');
        $this->fixture('daily.cvd',$now-8*86400);
        self::assertSame(
            ['main'=>false,'daily'=>true,'daily_fresh'=>false],
            ClamAvSignatureHealth::inspect($this->folder,$now)
        );
    }

    public function test_newer_daily_file_can_replace_an_old_one(): void
    {
        $now=1800000000;
        $this->fixture('main.cld',$now-86400);
        $this->fixture('daily.cvd',$now-30*86400);
        $this->fixture('daily.cld',$now-300);
        self::assertTrue(ClamAvSignatureHealth::inspect($this->folder,$now)['daily_fresh']);
    }

    public function test_future_modified_signatures_outside_clock_skew_are_rejected(): void
    {
        $now=1800000000;
        $this->fixture('main.cvd',$now);
        $this->fixture('daily.cvd',$now+3600);
        self::assertFalse(ClamAvSignatureHealth::inspect($this->folder,$now)['daily_fresh']);
    }
}
