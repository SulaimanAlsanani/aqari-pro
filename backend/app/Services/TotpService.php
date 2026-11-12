<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $group) {
            $encoded .= self::ALPHABET[bindec($group)];
        }

        return $encoded;
    }

    private function decode(string $secret): string
    {
        if (! preg_match('/^[A-Z2-7]{32}$/D', $secret)) {
            throw new \InvalidArgumentException('Invalid TOTP secret.');
        }
        $bits = '';
        foreach (str_split($secret) as $letter) {
            $index = strpos(self::ALPHABET, $letter);
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $group) {
            $binary .= chr(bindec($group));
        }

        return $binary;
    }

    private function codeForStep(string $secret, int $step): string
    {
        $key = $this->decode($secret);
        $high = intdiv($step, 4294967296);
        $low = $step % 4294967296;
        $mac = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($mac[19]) & 0x0f;
        $part = unpack('N', substr($mac, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($part % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        return $this->codeForStep($secret, intdiv($timestamp, 30));
    }

    public function matchingStep(string $secret, string $code, int $timestamp): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }

        $nowStep = intdiv($timestamp, 30);
        foreach ([$nowStep, $nowStep - 1, $nowStep + 1] as $step) {
            if ($step >= 0 && hash_equals($this->codeForStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function provisioningUri(string $secret, string $email): string
    {
        return 'otpauth://totp/'.rawurlencode('Aqari Pro:'.$email).
            '?secret='.$secret.'&issuer='.rawurlencode('Aqari Pro').
            '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Consume one 30-second step in one SQL operation to prevent a second
     * request from reusing the same valid TOTP code, including in parallel.
     */
    public function consume(User $user, string $code): bool
    {
        $row = DB::table('users')->where('id', $user->id)->first([
            'mfa_secret', 'mfa_enabled_at',
        ]);

        if (! $row || ! $row->mfa_secret || ! $row->mfa_enabled_at) {
            return false;
        }

        $secret = Crypt::decryptString($row->mfa_secret);
        $step = $this->matchingStep($secret, $code, now()->timestamp);
        if ($step === null) {
            return false;
        }

        return DB::table('users')->where('id', $user->id)
            ->whereNotNull('mfa_enabled_at')
            ->where(function ($query) use ($step): void {
                $query->whereNull('mfa_last_step')
                    ->orWhere('mfa_last_step', '<', $step);
            })
            ->update(['mfa_last_step' => $step]) === 1;
    }
}
