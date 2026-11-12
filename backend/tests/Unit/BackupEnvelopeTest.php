<?php
namespace Tests\Unit;

use App\Support\BackupEnvelope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupEnvelopeTest extends TestCase
{
    public function test_authenticated_encryption_round_trip_and_nonce_randomness(): void
    {
        $key=base64_encode(random_bytes(32));
        $data="sqlite\0backup\0".random_bytes(128);
        $one=BackupEnvelope::encrypt($data,$key);
        $two=BackupEnvelope::encrypt($data,$key);
        self::assertNotSame($one,$two);
        self::assertSame($data,BackupEnvelope::decrypt($one,$key));
    }

    public function test_tampered_backup_and_wrong_key_are_rejected(): void
    {
        $key=base64_encode(random_bytes(32));
        $backup=BackupEnvelope::encrypt('critical content',$key);
        $tampered=$backup;
        $tampered[strlen($tampered)-1]=chr(ord($tampered[strlen($tampered)-1]) ^ 1);
        try {
            BackupEnvelope::decrypt($tampered,$key);
            self::fail('Tampered ciphertext was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('authentication', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        BackupEnvelope::decrypt($backup,base64_encode(random_bytes(32)));
    }

    public function test_malformed_key_and_envelope_fail_closed(): void
    {
        $this->expectException(RuntimeException::class);
        BackupEnvelope::encrypt('data','invalid-key');
    }
}
