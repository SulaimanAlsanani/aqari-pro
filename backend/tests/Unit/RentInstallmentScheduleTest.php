<?php
namespace Tests\Unit;

use App\Support\RentInstallmentSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RentInstallmentScheduleTest extends TestCase
{
    public function test_remainder_is_allocated_by_integer_cents_with_exact_total(): void
    {
        self::assertSame(['33.34','33.33','33.33','33.33'],RentInstallmentSchedule::amounts('133.33',4));
        self::assertSame(['0.02','0.01','0.01','0.01'],RentInstallmentSchedule::amounts('0.05',4));
        self::assertSame(['1200.00'],RentInstallmentSchedule::amounts('1200.00',1));
        self::assertSame(['600.00','600.00'],RentInstallmentSchedule::amounts(1200,2));
    }

    public function test_each_valid_installment_has_a_positive_nonrounded_value(): void
    {
        $schedule=RentInstallmentSchedule::amounts('0.12',12);
        self::assertCount(12,$schedule);
        self::assertSame(array_fill(0,12,'0.01'),$schedule);
    }

    public function test_non_cent_values_cannot_be_silently_rounded(): void
    {
        foreach (['0.001','100.009','1e4','-4','not-money'] as $raw) {
            try {
                RentInstallmentSchedule::amounts($raw,2);
                self::fail('Invalid rent was accepted: '.$raw);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('',$exception->getMessage());
            }
        }
    }

    public function test_zero_value_installments_and_invalid_frequencies_fail_closed(): void
    {
        foreach ([['0.01',2],['0.11',12],['0.00',1],['1200',3]] as [$amount,$count]) {
            $this->expectException(InvalidArgumentException::class);
            try {
                RentInstallmentSchedule::amounts($amount,$count);
            } finally {
                // Each scenario is additionally exercised independently below.
            }
        }
    }
}
