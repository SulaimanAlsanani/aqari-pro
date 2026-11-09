<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class InstallmentSchedule
{
    public static function make(string $start, string $end, string $amount, int $count): array
    {
        if ($count < 1 || $count > 120 || !preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Invalid installment parameters.');
        }

        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $end);
        if ($startDate === false || $endDate === false || $startDate->format('Y-m-d') !== $start ||
            $endDate->format('Y-m-d') !== $end || $startDate->greaterThan($endDate)) {
            throw new InvalidArgumentException('Invalid contract dates.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $total = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($total < $count) {
            throw new InvalidArgumentException('Contract value must cover every installment.');
        }

        $base = intdiv($total, $count);
        $remainder = $total % $count;
        $result = [];

        for ($index = 0; $index < $count; $index++) {
            // Anchor every month to the same original date (prevents month-end drift).
            $month = $startDate->startOfMonth()->addMonths($index);
            $day = min($startDate->day, $month->daysInMonth);
            $dueDate = $month->setDay($day);

            if ($dueDate->greaterThan($endDate)) {
                throw new InvalidArgumentException('Installment date exceeds contract end.');
            }

            $cents = $base + ($index < $remainder ? 1 : 0);
            $result[] = [
                'due_date' => $dueDate->format('Y-m-d'),
                'amount' => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT),
            ];
        }

        return $result;
    }
}
