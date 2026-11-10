<?php

namespace App\Services;

use InvalidArgumentException;

final class Money
{
    public static function cents(string $value): int
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Invalid SAR amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'
            .str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
