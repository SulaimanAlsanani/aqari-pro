<?php
namespace Tests\Unit;

use App\Support\LeaseDueDates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LeaseDueDatesTest extends TestCase
{
    public function test_full_year_monthly_and_quarterly_due_dates_are_unique_and_in_term(): void
    {
        $monthly=LeaseDueDates::forTerm('2027-01-01','2027-12-31',12);
        self::assertCount(12,$monthly);
        self::assertCount(12,array_unique($monthly));
        self::assertSame('2027-01-01',$monthly[0]);
        self::assertSame('2027-12-01',$monthly[11]);

        $quarterly=LeaseDueDates::forTerm('2027-01-01','2027-12-31',4);
        self::assertSame(
            ['2027-01-01','2027-04-01','2027-07-01','2027-10-01'],
            $quarterly
        );
    }

    public function test_month_end_payment_dates_use_no_overflow_calendar_rules(): void
    {
        $dates=LeaseDueDates::forTerm('2027-01-31','2028-01-30',12);
        self::assertSame('2027-02-28',$dates[1]);
        self::assertSame('2027-03-31',$dates[2]);
        self::assertSame('2027-11-30',$dates[10]);
    }

    public function test_short_lease_cannot_move_excess_installments_to_end_date(): void
    {
        foreach ([
            ['2027-01-01','2027-02-28',4],
            ['2027-01-01','2027-01-20',2],
            ['2027-01-01','2027-06-30',12],
            ['2027-01-01','2027-12-31',3],
            ['2027-03-01','2027-02-28',1],
        ] as [$start,$end,$count]) {
            try {
                LeaseDueDates::forTerm($start,$end,$count);
                self::fail('Invalid lease schedule was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('',$exception->getMessage());
            }
        }
    }
}
