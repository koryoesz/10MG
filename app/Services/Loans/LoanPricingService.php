<?php

namespace App\Services\Loans;

use App\Exceptions\Api\LoanTermsViolation;
use App\Models\LoanProduct;
use App\Services\Lending\FeeTerm;
use App\Services\Lending\LoanQuote;
use App\Services\Lending\RepaymentScheduleCalculator;
use App\Services\PlatformFeeService;
use Carbon\CarbonImmutable;

/**
 * Prices a request against a product's current configuration, enforcing its limits.
 */
class LoanPricingService
{
    public function __construct(
        private RepaymentScheduleCalculator $calculator,
        private PlatformFeeService $platformFees,
    ) {}

    /**
     * Public quote: the product must be on sale.
     *
     * @throws LoanTermsViolation
     */
    public function quoteForSale(LoanProduct $product, int $amount, int $tenor): LoanQuote
    {
        if (! $product->is_active) {
            throw new LoanTermsViolation('This loan product is not currently available.', ['loan_product_id' => 'inactive']);
        }

        return $this->quote($product, $amount, $tenor);
    }

    /**
     * @throws LoanTermsViolation
     */
    public function quote(LoanProduct $product, int $amount, int $tenor, ?CarbonImmutable $startDate = null): LoanQuote
    {
        $violations = array_filter([
            'amount' => $amount < $product->min_amount || $amount > $product->max_amount
                ? "Amount must be between {$product->min_amount} and {$product->max_amount} (minor units)."
                : null,
            'tenor' => $tenor < $product->min_tenor || $tenor > $product->max_tenor
                ? "Tenor must be between {$product->min_tenor} and {$product->max_tenor} installments."
                : null,
        ]);

        if ($violations !== []) {
            throw new LoanTermsViolation(details: $violations);
        }

        $product->loadMissing(['fees', 'institution']);

        // When the lender passes 10MG's fee on to the borrower, it is priced like any upfront fee.
        $fees = $product->fees->map(FeeTerm::fromProductFee(...))->values()->all();
        $platformFee = $this->platformFees->borrowerFeeTerm($product->institution);

        if ($platformFee !== null) {
            $fees[] = $platformFee;
        }

        return $this->calculator->quote(
            principal: $amount,
            tenor: $tenor,
            interestRateBps: $product->interest_rate_bps,
            method: $product->interest_method,
            frequency: $product->repayment_frequency,
            fees: $fees,
            startDate: $startDate,
        );
    }
}
