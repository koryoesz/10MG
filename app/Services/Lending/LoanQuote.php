<?php

namespace App\Services\Lending;

use App\Enums\InterestMethod;
use App\Enums\RepaymentFrequency;

/**
 * The full priced result of a set of loan terms. All amounts are in minor units.
 */
final readonly class LoanQuote
{
    /**
     * @param  list<ScheduleLine>  $schedule
     * @param  list<FeeTerm>  $fees
     */
    public function __construct(
        public int $principal,
        public int $tenor,
        public int $interestRateBps,
        public InterestMethod $interestMethod,
        public RepaymentFrequency $frequency,
        public array $fees,
        public int $totalInterest,
        public int $upfrontFees,
        public int $capitalizedFees,
        public array $schedule,
    ) {}

    public function netDisbursement(): int
    {
        return $this->principal - $this->upfrontFees;
    }

    public function totalRepayable(): int
    {
        return $this->principal + $this->totalInterest + $this->capitalizedFees;
    }

    /**
     * The largest regular installment, used for affordability checks.
     */
    public function installmentAmount(): int
    {
        return max(array_map(fn (ScheduleLine $line): int => $line->total(), $this->schedule));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'principal_amount' => $this->principal,
            'tenor' => $this->tenor,
            'interest_rate_bps' => $this->interestRateBps,
            'interest_method' => $this->interestMethod->value,
            'repayment_frequency' => $this->frequency->value,
            'total_interest' => $this->totalInterest,
            'upfront_fees' => $this->upfrontFees,
            'capitalized_fees' => $this->capitalizedFees,
            'net_disbursement_amount' => $this->netDisbursement(),
            'total_repayable' => $this->totalRepayable(),
            'installment_amount' => $this->installmentAmount(),
            'fees' => array_map(fn (FeeTerm $fee): array => $fee->toArray() + ['amount' => $fee->amountFor($this->principal)], $this->fees),
            'schedule' => array_map(fn (ScheduleLine $line): array => $line->toArray(), $this->schedule),
        ];
    }
}
