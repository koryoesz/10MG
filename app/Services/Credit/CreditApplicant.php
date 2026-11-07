<?php

namespace App\Services\Credit;

use App\Enums\BusinessType;
use App\Enums\RepaymentFrequency;

/**
 * The business profile and requested terms a scoring provider needs. Amounts in minor units.
 */
final readonly class CreditApplicant
{
    public function __construct(
        public BusinessType $businessType,
        public int $yearsInOperation,
        public bool $hasValidLicence,
        public int $monthlyRevenue,
        public int $monthlyExpenses,
        public int $requestedAmount,
        public int $installmentAmount,
        public RepaymentFrequency $frequency,
        public int $openLoanCount,
        public ?int $minimumScore = null,
    ) {}

    public function netMonthlyCashFlow(): int
    {
        return $this->monthlyRevenue - $this->monthlyExpenses;
    }

    /**
     * The installment normalised to a monthly obligation, so affordability is comparable across frequencies.
     */
    public function monthlyObligation(): int
    {
        return intdiv($this->installmentAmount * $this->frequency->periodsPerYear() + 11, 12);
    }

    /**
     * Debt service coverage ratio in basis points (15000 = 1.50x): how many times the business's
     * net monthly cash flow covers the new loan's monthly repayment.
     */
    public function debtServiceCoverageBps(): int
    {
        $obligation = $this->monthlyObligation();

        if ($obligation <= 0 || $this->netMonthlyCashFlow() <= 0) {
            return 0;
        }

        return min(999_999, intdiv($this->netMonthlyCashFlow() * 10000, $obligation));
    }
}
