<?php

namespace App\Services\Lending;

use App\Enums\FeeChargeType;
use App\Enums\InterestMethod;
use App\Enums\RepaymentFrequency;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pure pricing engine: turns loan terms into totals and an installment schedule.
 *
 * Arithmetic is done with bcmath at high precision and rounded half-up to minor units only
 * when an amount is materialised. Rounding residue is absorbed by the final installment so
 * the schedule always sums exactly to the quoted totals.
 */
class RepaymentScheduleCalculator
{
    private const SCALE = 20;

    /**
     * @param  list<FeeTerm>  $fees
     */
    public function quote(
        int $principal,
        int $tenor,
        int $interestRateBps,
        InterestMethod $method,
        RepaymentFrequency $frequency,
        array $fees = [],
        ?CarbonImmutable $startDate = null,
    ): LoanQuote {
        if ($principal <= 0 || $tenor <= 0 || $interestRateBps < 0) {
            throw new InvalidArgumentException('Principal and tenor must be positive and the rate non-negative.');
        }

        $startDate ??= CarbonImmutable::today();
        $periodicRate = bcdiv((string) $interestRateBps, (string) (10000 * $frequency->periodsPerYear()), self::SCALE);

        [$upfrontFees, $capitalizedFees] = $this->totalFees($fees, $principal);

        [$principalPerPeriod, $interestPerPeriod] = match ($method) {
            InterestMethod::Flat => $this->flat($principal, $tenor, $periodicRate),
            InterestMethod::ReducingBalance => $this->amortise($principal, $tenor, $periodicRate),
        };

        $feePerPeriod = $this->spread($capitalizedFees, $tenor);

        $schedule = [];
        $balance = $principal;

        for ($period = 0; $period < $tenor; $period++) {
            $schedule[] = new ScheduleLine(
                sequence: $period + 1,
                dueDate: $frequency->dueDate($startDate, $period + 1),
                principal: $principalPerPeriod[$period],
                interest: $interestPerPeriod[$period],
                fee: $feePerPeriod[$period],
                openingBalance: $balance,
            );
            $balance -= $principalPerPeriod[$period];
        }

        return new LoanQuote(
            principal: $principal,
            tenor: $tenor,
            interestRateBps: $interestRateBps,
            interestMethod: $method,
            frequency: $frequency,
            fees: $fees,
            totalInterest: array_sum($interestPerPeriod),
            upfrontFees: $upfrontFees,
            capitalizedFees: $capitalizedFees,
            schedule: $schedule,
        );
    }

    /**
     * @param  list<FeeTerm>  $fees
     * @return array{0: int, 1: int}
     */
    private function totalFees(array $fees, int $principal): array
    {
        $upfront = 0;
        $capitalized = 0;

        foreach ($fees as $fee) {
            if ($fee->chargeType === FeeChargeType::Upfront) {
                $upfront += $fee->amountFor($principal);
            } else {
                $capitalized += $fee->amountFor($principal);
            }
        }

        if ($upfront >= $principal) {
            throw new InvalidArgumentException('Upfront fees cannot consume the entire principal.');
        }

        return [$upfront, $capitalized];
    }

    /**
     * Flat: interest on the original principal for every period; principal and interest
     * are each spread evenly.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function flat(int $principal, int $tenor, string $periodicRate): array
    {
        $totalInterest = $this->round(bcmul(bcmul((string) $principal, $periodicRate, self::SCALE), (string) $tenor, self::SCALE));

        return [$this->spread($principal, $tenor), $this->spread($totalInterest, $tenor)];
    }

    /**
     * Reducing balance: equal installments (EMI) where each period's interest is charged on
     * the principal still outstanding. The final period clears the remaining balance.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function amortise(int $principal, int $tenor, string $periodicRate): array
    {
        $emi = $this->emi($principal, $tenor, $periodicRate);
        $principalParts = [];
        $interestParts = [];
        $balance = $principal;

        for ($period = 1; $period <= $tenor; $period++) {
            $interest = $this->round(bcmul((string) $balance, $periodicRate, self::SCALE));
            $principalPart = $period === $tenor ? $balance : max(0, min($balance, $emi - $interest));

            $principalParts[] = $principalPart;
            $interestParts[] = $interest;
            $balance -= $principalPart;
        }

        return [$principalParts, $interestParts];
    }

    /**
     * EMI = P·r / (1 − (1 + r)^−n), or P / n when the rate is zero.
     */
    private function emi(int $principal, int $tenor, string $periodicRate): int
    {
        if (bccomp($periodicRate, '0', self::SCALE) === 0) {
            return intdiv($principal + $tenor - 1, $tenor);
        }

        $growth = bcpow(bcadd('1', $periodicRate, self::SCALE), (string) $tenor, self::SCALE);
        $discount = bcdiv('1', $growth, self::SCALE);
        $denominator = bcsub('1', $discount, self::SCALE);

        return $this->round(bcdiv(bcmul((string) $principal, $periodicRate, self::SCALE), $denominator, self::SCALE));
    }

    /**
     * Split an amount into equal integer parts; the remainder goes to the last part.
     *
     * @return list<int>
     */
    private function spread(int $amount, int $parts): array
    {
        $base = intdiv($amount, $parts);
        $result = array_fill(0, $parts, $base);
        $result[$parts - 1] += $amount - ($base * $parts);

        return $result;
    }

    /**
     * Round a non-negative decimal string half-up to an integer.
     */
    private function round(string $value): int
    {
        return (int) bcadd($value, '0.5', 0);
    }
}
