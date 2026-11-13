<?php
namespace App\Support;

use InvalidArgumentException;

/**
 * Exact, deterministic allocation of a validated lease amount (SAR) into
 * positive two-decimal installments. Never uses floating point arithmetic.
 */
final class RentInstallmentSchedule
{
    /**
     * @return list<string> Ordered decimal amounts such as ["33.34", "33.33", "33.33"].
     */
    public static function amounts(string|int|float $amount, int $installments): array
    {
        if (!in_array($installments, [1, 2, 4, 12], true)) {
            throw new InvalidArgumentException('Unsupported installment frequency.');
        }

        $raw=(string) $amount;
        // Decimal string, maximum 10 digits before decimal, maximum 2 after.
        // Do not silently round unvalidated amounts or accept scientific notation.
        if (!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $raw, $matches)) {
            throw new InvalidArgumentException('Rent must have at most two decimal places.');
        }
        $riyals=(int) $matches[1];
        $fraction=str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
        $totalCents=$riyals*100+(int)$fraction;
        if ($totalCents < $installments) {
            throw new InvalidArgumentException('Each rent installment must be at least 0.01 SAR.');
        }

        $base=intdiv($totalCents,$installments);
        $remainder=$totalCents%$installments;
        $result=[];
        for ($i=0;$i<$installments;$i++) {
            $cents=$base+($i<$remainder ? 1 : 0);
            $result[]=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
        }
        return $result;
    }
}
