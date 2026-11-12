<?php
namespace Tests\Unit;

use App\Support\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function test_rfc_6238_sha1_vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::assertSame('94287082', Totp::code($secret, 59, 8));
        self::assertSame('07081804', Totp::code($secret, 1111111109, 8));
        self::assertSame('14050471', Totp::code($secret, 1111111111, 8));
        self::assertSame('89005924', Totp::code($secret, 1234567890, 8));
        self::assertSame('69279037', Totp::code($secret, 2000000000, 8));
    }

    public function test_generated_secret_is_random_base32_and_usable(): void
    {
        $one = Totp::generateSecret();
        $two = Totp::generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $one);
        self::assertNotSame($one, $two);
        self::assertTrue(Totp::verify($one, Totp::code($one, 1700000000), 1700000000));
    }

    public function test_verification_accepts_at_most_one_neighboring_step(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $code = Totp::code($secret, 90);
        self::assertTrue(Totp::verify($secret, $code, 60));
        self::assertTrue(Totp::verify($secret, $code, 120));
        self::assertFalse(Totp::verify($secret, $code, 150));
        self::assertFalse(Totp::verify($secret, 'not-a-code', 90));
        self::assertFalse(Totp::verify($secret, $code, -1));
    }

    public function test_invalid_secret_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::code('INVALID-SECRET', 59);
    }
}
