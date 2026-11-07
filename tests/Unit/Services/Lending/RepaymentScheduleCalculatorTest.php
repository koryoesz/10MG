<?php

namespace Tests\Unit\Services\Lending;

use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use App\Enums\InterestMethod;
use App\Enums\RepaymentFrequency;
use App\Services\Lending\FeeTerm;
use App\Services\Lending\LoanQuote;
use App\Services\Lending\RepaymentScheduleCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepaymentScheduleCalculatorTest extends TestCase
{
    private RepaymentScheduleCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RepaymentScheduleCalculator;
    }

    public function test_flat_interest_is_charged_on_the_original_principal_for_every_period(): void
    {
        // ₦100,000 at 24% p.a. flat over 6 months = ₦12,000 interest.
        $quote = $this->calculator->quote(10_000_000, 6, 2400, InterestMethod::Flat, RepaymentFrequency::Monthly);

        $this->assertSame(1_200_000, $quote->totalInterest);
        $this->assertSame(11_200_000, $quote->totalRepayable());
        $this->assertSame(array_fill(0, 6, 200_000), array_map(fn ($line) => $line->interest, $quote->schedule));
    }

    public function test_reducing_balance_produces_equal_installments_matching_the_emi_formula(): void
    {
        // ₦500,000 at 24% p.a. (2%/month) over 6 months: EMI = ₦89,262.91, total interest ₦35,577.44.
        $quote = $this->calculator->quote(50_000_000, 6, 2400, InterestMethod::ReducingBalance, RepaymentFrequency::Monthly);

        $this->assertSame(3_557_744, $quote->totalInterest);
        $this->assertSame(1_000_000, $quote->schedule[0]->interest);
        $this->assertSame(8_926_291, $quote->schedule[0]->total());

        foreach ($quote->schedule as $line) {
            $this->assertEqualsWithDelta(8_926_291, $line->total(), $quote->tenor, 'Only rounding residue may differ.');
        }
    }

    public function test_reducing_balance_charges_less_interest_than_flat_for_the_same_terms(): void
    {
        $flat = $this->calculator->quote(50_000_000, 12, 3000, InterestMethod::Flat, RepaymentFrequency::Monthly);
        $reducing = $this->calculator->quote(50_000_000, 12, 3000, InterestMethod::ReducingBalance, RepaymentFrequency::Monthly);

        $this->assertLessThan($flat->totalInterest, $reducing->totalInterest);
    }

    /**
     * @return array<string, array{int, int, int, InterestMethod, RepaymentFrequency}>
     */
    public static function awkwardTerms(): array
    {
        return [
            'flat, indivisible principal' => [10_000_001, 7, 1999, InterestMethod::Flat, RepaymentFrequency::Monthly],
            'reducing, weekly, long tenor' => [12_345_678, 52, 3650, InterestMethod::ReducingBalance, RepaymentFrequency::Weekly],
            'reducing, biweekly' => [999_999, 13, 1, InterestMethod::ReducingBalance, RepaymentFrequency::Biweekly],
            'single installment' => [5_000_000, 1, 2400, InterestMethod::ReducingBalance, RepaymentFrequency::Monthly],
        ];
    }

    #[DataProvider('awkwardTerms')]
    public function test_schedule_sums_exactly_to_quoted_totals_despite_rounding(int $principal, int $tenor, int $rate, InterestMethod $method, RepaymentFrequency $frequency): void
    {
        $quote = $this->calculator->quote($principal, $tenor, $rate, $method, $frequency, $this->standardFees());

        $this->assertCount($tenor, $quote->schedule);
        $this->assertSame($principal, $this->sum($quote, 'principal'));
        $this->assertSame($quote->totalInterest, $this->sum($quote, 'interest'));
        $this->assertSame($quote->capitalizedFees, $this->sum($quote, 'fee'));
        $this->assertSame($quote->totalRepayable(), array_sum(array_map(fn ($line) => $line->total(), $quote->schedule)));
    }

    public function test_zero_interest_rate_repays_principal_only(): void
    {
        $quote = $this->calculator->quote(1_000_000, 3, 0, InterestMethod::ReducingBalance, RepaymentFrequency::Monthly);

        $this->assertSame(0, $quote->totalInterest);
        $this->assertSame(1_000_000, $quote->totalRepayable());
    }

    public function test_upfront_fees_reduce_disbursement_and_capitalized_fees_increase_repayable(): void
    {
        $quote = $this->calculator->quote(10_000_000, 4, 0, InterestMethod::Flat, RepaymentFrequency::Monthly, $this->standardFees());

        $this->assertSame(100_000, $quote->upfrontFees);
        $this->assertSame(50_000, $quote->capitalizedFees);
        $this->assertSame(9_900_000, $quote->netDisbursement());
        $this->assertSame(10_050_000, $quote->totalRepayable());
        $this->assertSame(12_500, $quote->schedule[0]->fee);
    }

    public function test_due_dates_follow_the_repayment_frequency(): void
    {
        $start = CarbonImmutable::parse('2026-01-31');

        $monthly = $this->calculator->quote(1_000_000, 2, 0, InterestMethod::Flat, RepaymentFrequency::Monthly, startDate: $start);
        $weekly = $this->calculator->quote(1_000_000, 2, 0, InterestMethod::Flat, RepaymentFrequency::Weekly, startDate: $start);
        $biweekly = $this->calculator->quote(1_000_000, 2, 0, InterestMethod::Flat, RepaymentFrequency::Biweekly, startDate: $start);

        $this->assertSame('2026-02-28', $monthly->schedule[0]->dueDate->toDateString());
        $this->assertSame('2026-03-31', $monthly->schedule[1]->dueDate->toDateString());
        $this->assertSame('2026-02-07', $weekly->schedule[0]->dueDate->toDateString());
        $this->assertSame('2026-02-28', $biweekly->schedule[1]->dueDate->toDateString());
    }

    public function test_rejects_upfront_fees_that_consume_the_whole_principal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->quote(10_000, 1, 0, InterestMethod::Flat, RepaymentFrequency::Monthly, [
            new FeeTerm('Huge', FeeCalculation::Fixed, 10_000, FeeChargeType::Upfront),
        ]);
    }

    /**
     * @return list<FeeTerm>
     */
    private function standardFees(): array
    {
        return [
            new FeeTerm('Processing', FeeCalculation::Percentage, 100, FeeChargeType::Upfront),
            new FeeTerm('Insurance', FeeCalculation::Fixed, 50_000, FeeChargeType::Capitalized),
        ];
    }

    private function sum(LoanQuote $quote, string $field): int
    {
        return array_sum(array_map(fn ($line) => $line->{$field}, $quote->schedule));
    }
}
