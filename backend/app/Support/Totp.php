<?php
namespace App\Support;

use InvalidArgumentException;

final class Totp
{
    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= $alphabet[bindec($chunk)];
        }
        return $secret;
    }

    public static function code(string $secret, int $timestamp, int $digits = 6): string
    {
        if ($timestamp < 0 || $digits < 6 || $digits > 8) {
            throw new InvalidArgumentException('Invalid TOTP parameters.');
        }
        $key = self::decodeSecret($secret);
        $counter = intdiv($timestamp, 30);
        $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $timestamp, int $window = 1): bool
    {
        if (!preg_match('/^[0-9]{6}$/D', $code) || $timestamp < 0 || $window < 0 || $window > 1) {
            return false;
        }
        for ($offset = -$window; $offset <= $window; $offset++) {
            $at = $timestamp + 30 * $offset;
            if ($at >= 0 && hash_equals(self::code($secret, $at), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function decodeSecret(string $secret): string
    {
        if (!preg_match('/^[A-Z2-7]{16,}$/D', $secret)) {
            throw new InvalidArgumentException('Invalid base32 secret.');
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $result .= chr(bindec($byte));
        }
        if ($result === '' || trim(substr($bits, intdiv(strlen($bits), 8) * 8), '0') !== '') {
            throw new InvalidArgumentException('Invalid base32 padding bits.');
        }
        return $result;
    }
}
