<?php

namespace App\Services\Loans;

use App\Contracts\CreditBureau;
use App\Enums\LoanPurpose;
use App\Enums\LoanStatus;
use App\Exceptions\Api\KycRequired;
use App\Exceptions\Api\LoanTermsViolation;
use App\Exceptions\Api\OpenLoanLimitReached;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\LoanProductRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Services\AuditLogger;
use App\Services\Credit\CreditApplicant;
use App\Services\Credit\CreditDecision;
use App\Services\KycSettingsService;
use App\Services\Lending\FeeTerm;
use App\Services\Lending\LoanQuote;
use App\Services\PlatformFeeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A borrower applies for a loan: terms are validated and priced, product terms are
 * snapshotted onto the loan, and the business profile is scored.
 */
class LoanApplicationService
{
    public function __construct(
        private LoanPricingService $pricing,
        private CreditBureau $creditBureau,
        private CustomerRepositoryInterface $customers,
        private LoanProductRepositoryInterface $products,
        private LoanRepositoryInterface $loans,
        private KycSettingsService $kycSettings,
        private PlatformFeeService $platformFees,
        private AuditLogger $audit,
    ) {}

    /**
     * Apply on behalf of the borrower that the given user owns.
     */
    public function applyAs(User $owner, string $productId, int $amount, int $tenor, LoanPurpose $purpose, ?string $purposeNote = null): Loan
    {
        return $this->apply(
            $this->customers->findForUser($owner),
            $this->products->findOrFail($productId),
            $amount,
            $tenor,
            $purpose,
            $purposeNote,
        );
    }

    public function apply(Customer $customer, LoanProduct $product, int $amount, int $tenor, LoanPurpose $purpose, ?string $purposeNote = null): Loan
    {
        $this->ensureEligible($customer, $product);
        $quote = $this->pricing->quote($product, $amount, $tenor);

        return DB::transaction(function () use ($customer, $product, $quote, $purpose, $purposeNote): Loan {
            // Serialise applications per borrower so the open-loan limit cannot be raced.
            $customer = $this->customers->lockForUpdate($customer);
            $openLoans = $this->ensureBelowOpenLoanLimit($customer);

            $loan = $this->loans->create($this->loanAttributes($customer, $product, $quote, $purpose, $purposeNote));
            $decision = $this->creditBureau->assess($this->applicant($customer, $product, $quote, $openLoans));
            $assessment = $this->loans->recordAssessment($loan, $this->assessmentAttributes($decision));

            $this->audit->record('loan.applied', $loan, newValues: [
                'reference' => $loan->reference,
                'principal_amount' => $loan->principal_amount,
                'tenor' => $loan->tenor,
                'purpose' => $purpose->value,
                'credit_score' => $decision->score,
                'recommendation' => $decision->recommendation->value,
            ]);

            return $loan->setRelation('creditAssessment', $assessment);
        });
    }

    private function ensureEligible(Customer $customer, LoanProduct $product): void
    {
        $kyc = $this->kycSettings->current();

        if (! $customer->meetsKycRequirements($kyc)) {
            throw new KycRequired(details: [
                'kyc_status' => $customer->kycStatusUnder($kyc)->value,
                'outstanding_checks' => $customer->outstandingKycChecks($kyc),
            ]);
        }

        if (! $product->is_active) {
            throw new LoanTermsViolation('This loan product is not currently available.', ['loan_product_id' => 'inactive']);
        }

        if (! $product->isAvailableTo($customer->business_type)) {
            throw new LoanTermsViolation(
                'This loan product is not offered to this type of business.',
                ['business_type' => $customer->business_type->value, 'eligible_business_types' => $product->eligible_business_types],
            );
        }
    }

    private function ensureBelowOpenLoanLimit(Customer $customer): int
    {
        $openLoans = $this->loans->countOpenFor($customer);
        $limit = config('lending.max_open_loans_per_customer');

        if ($openLoans >= $limit) {
            throw new OpenLoanLimitReached(details: ['open_loans' => $openLoans, 'limit' => $limit]);
        }

        return $openLoans;
    }

    /**
     * @return array<string, mixed>
     */
    private function loanAttributes(Customer $customer, LoanProduct $product, LoanQuote $quote, LoanPurpose $purpose, ?string $purposeNote): array
    {
        return [
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'reference' => 'LN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'status' => LoanStatus::Pending,
            'currency' => $product->currency,
            'principal_amount' => $quote->principal,
            'tenor' => $quote->tenor,
            'interest_rate_bps' => $quote->interestRateBps,
            'interest_method' => $quote->interestMethod,
            'repayment_frequency' => $quote->frequency,
            'fees_snapshot' => array_map(fn (FeeTerm $fee): array => $fee->toArray(), $quote->fees),
            'grace_period_days' => $product->grace_period_days,
            'late_fee_type' => $product->late_fee_type,
            'late_fee_value' => $product->late_fee_value,
            'total_interest' => $quote->totalInterest,
            'upfront_fees' => $quote->upfrontFees,
            'capitalized_fees' => $quote->capitalizedFees,
            'net_disbursement_amount' => $quote->netDisbursement(),
            'total_repayable' => $quote->totalRepayable(),
            'outstanding_balance' => 0,
            ...$this->platformFees->snapshotFor($product->institution, $quote->principal),
            'purpose' => $purpose,
            'purpose_note' => $purposeNote,
        ];
    }

    private function applicant(Customer $customer, LoanProduct $product, LoanQuote $quote, int $openLoans): CreditApplicant
    {
        return new CreditApplicant(
            businessType: $customer->business_type,
            yearsInOperation: $customer->yearsInOperation(),
            hasValidLicence: $customer->hasValidLicence(),
            monthlyRevenue: $customer->monthly_revenue,
            monthlyExpenses: $customer->monthly_expenses,
            requestedAmount: $quote->principal,
            installmentAmount: $quote->installmentAmount(),
            frequency: $quote->frequency,
            openLoanCount: $openLoans,
            minimumScore: $product->min_credit_score,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentAttributes(CreditDecision $decision): array
    {
        return [
            'provider' => $decision->provider,
            'score' => $decision->score,
            'risk_grade' => $decision->riskGrade,
            'recommendation' => $decision->recommendation,
            'debt_service_coverage_bps' => $decision->debtServiceCoverageBps,
            'net_monthly_cash_flow' => $decision->netMonthlyCashFlow,
            'max_recommended_amount' => $decision->maxRecommendedAmount,
            'factors' => $decision->factors,
            'assessed_at' => now(),
        ];
    }
}
