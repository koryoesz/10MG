<?php

namespace Tests\Unit\Services\Credit;

use App\Enums\BusinessType;
use App\Enums\CreditRecommendation;
use App\Enums\RepaymentFrequency;
use App\Services\Credit\CreditApplicant;
use App\Services\Credit\MockHealthcareCreditScorer;
use PHPUnit\Framework\TestCase;

class MockHealthcareCreditScorerTest extends TestCase
{
    public function test_recommends_approval_for_an_established_licensed_pharmacy_with_strong_coverage(): void
    {
        // ₦1m net cash flow against a ₦250k monthly repayment = 4.00x coverage.
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant());

        $this->assertSame(CreditRecommendation::Approve, $decision->recommendation);
        $this->assertSame(40000, $decision->debtServiceCoverageBps);
        $this->assertSame(100_000_000, $decision->netMonthlyCashFlow);
        $this->assertSame(810, $decision->score);
        $this->assertSame('A', $decision->riskGrade);
    }

    public function test_declines_when_the_operating_licence_has_expired(): void
    {
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant(validLicence: false));

        $this->assertSame(CreditRecommendation::Decline, $decision->recommendation);
        $this->assertContains(['factor' => 'operating_licence', 'impact' => -200, 'detail' => 'expired'], $decision->factors);
    }

    public function test_declines_when_cash_flow_covers_the_repayment_less_than_1_2_times(): void
    {
        // ₦1m net cash flow against a ₦900k repayment = 1.11x.
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant(installment: 90_000_000));

        $this->assertSame(CreditRecommendation::Decline, $decision->recommendation);
        $this->assertSame(11111, $decision->debtServiceCoverageBps);
    }

    public function test_declines_a_business_running_at_a_loss(): void
    {
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant(revenue: 100_000_000, expenses: 120_000_000));

        $this->assertSame(CreditRecommendation::Decline, $decision->recommendation);
        $this->assertSame(0, $decision->debtServiceCoverageBps);
        $this->assertSame(0, $decision->maxRecommendedAmount);
    }

    public function test_refers_borderline_coverage_for_manual_review(): void
    {
        // ₦1m / ₦800k = 1.25x: serviceable but thin.
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant(installment: 80_000_000));

        $this->assertSame(CreditRecommendation::Review, $decision->recommendation);
    }

    public function test_a_young_business_scores_lower_than_an_established_one(): void
    {
        $scorer = new MockHealthcareCreditScorer;

        $this->assertLessThan(
            $scorer->assess($this->applicant(years: 8))->score,
            $scorer->assess($this->applicant(years: 0))->score,
        );
    }

    public function test_product_minimum_score_overrides_the_default_cut_off(): void
    {
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant(minimumScore: 840));

        $this->assertSame(CreditRecommendation::Decline, $decision->recommendation);
    }

    public function test_weekly_repayments_are_normalised_to_a_monthly_obligation(): void
    {
        // ₦60k/week × 52 / 12 = ₦260k/month → ₦1m / ₦260k = 3.84x.
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant(installment: 6_000_000, frequency: RepaymentFrequency::Weekly));

        $this->assertSame(38461, $decision->debtServiceCoverageBps);
    }

    public function test_max_recommended_amount_keeps_coverage_at_1_5_times(): void
    {
        // Affordable repayment ₦1m / 1.5 = ₦666,666; requested ₦1m loan at ₦250k/month → ₦2,666,666.64.
        $decision = (new MockHealthcareCreditScorer)->assess($this->applicant());

        $this->assertSame(266_666_664, $decision->maxRecommendedAmount);
    }

    private function applicant(
        bool $validLicence = true,
        int $years = 6,
        int $revenue = 300_000_000,
        int $expenses = 200_000_000,
        int $installment = 25_000_000,
        RepaymentFrequency $frequency = RepaymentFrequency::Monthly,
        ?int $minimumScore = null,
    ): CreditApplicant {
        return new CreditApplicant(
            businessType: BusinessType::Pharmacy,
            yearsInOperation: $years,
            hasValidLicence: $validLicence,
            monthlyRevenue: $revenue,
            monthlyExpenses: $expenses,
            requestedAmount: 100_000_000,
            installmentAmount: $installment,
            frequency: $frequency,
            openLoanCount: 0,
            minimumScore: $minimumScore,
        );
    }
}
