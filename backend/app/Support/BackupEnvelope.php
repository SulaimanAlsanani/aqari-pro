<?php
namespace App\Support;

use RuntimeException;

/**
 * Versioned AES-256-GCM envelope for backup payloads.
 * Key is independent from Laravel APP_KEY; never commit or log it.
 */
final class BackupEnvelope
{
    private const MAGIC = "AQBK01";

    public static function encrypt(string $plaintext, string $base64Key): string
    {
        $key = self::key($base64Key);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            $nonce, $tag, self::MAGIC, 16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Backup encryption failed.');
        }
        return self::MAGIC.$nonce.$tag.$ciphertext;
    }

    public static function decrypt(string $envelope, string $base64Key): string
    {
        if (strlen($envelope) < 34 || !str_starts_with($envelope, self::MAGIC)) {
            throw new RuntimeException('Invalid backup envelope.');
        }
        $plaintext = openssl_decrypt(
            substr($envelope, 34), 'aes-256-gcm', self::key($base64Key),
            OPENSSL_RAW_DATA, substr($envelope, 6, 12), substr($envelope, 18, 16),
            self::MAGIC
        );
        if ($plaintext === false) {
            throw new RuntimeException('Backup authentication failed.');
        }
        return $plaintext;
    }

    private static function key(string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must encode 32 random bytes.');
        }
        return $key;
    }
}
