<?php
namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Calendar-based rent due dates for supported annual payment frequencies.
 * Refuses duplicate dates and periods that cannot accommodate the requested
 * schedule, instead of bunching excess payments on the contract end date.
 */
final class LeaseDueDates
{
    /**
     * @return list<string> Exact YYYY-MM-DD dates, including the first day.
     */
    public static function forTerm(string $startsOn, string $endsOn, int $installments): array
    {
        if (!in_array($installments, [1, 2, 4, 12], true)) {
            throw new InvalidArgumentException('Unsupported rent payment frequency.');
        }

        try {
            $start=CarbonImmutable::createFromFormat('!Y-m-d',$startsOn);
            $end=CarbonImmutable::createFromFormat('!Y-m-d',$endsOn);
        } catch (\\Throwable $error) {
            throw new InvalidArgumentException('Invalid lease date range.',0,$error);
        }
        if (!$start || !$end || $start->format('Y-m-d')!==$startsOn ||
            $end->format('Y-m-d')!==$endsOn || $end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Invalid lease date range.');
        }

        $dates=[];
        $last=null;
        $monthsPerPayment=intdiv(12,$installments);
        for ($i=0;$i<$installments;$i++) {
            $date=$start->addMonthsNoOverflow($i*$monthsPerPayment);
            if ($date->greaterThan($end) || ($last!==null && $date->lessThanOrEqualTo($last))) {
                throw new InvalidArgumentException(
                    'Lease period is too short for the selected installment frequency.'
                );
            }
            $dates[]=$date->toDateString();
            $last=$date;
        }

        return $dates;
    }
}
