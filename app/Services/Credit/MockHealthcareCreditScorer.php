<?php

namespace App\Services\Credit;

use App\Contracts\CreditBureau;
use App\Enums\BusinessType;
use App\Enums\CreditRecommendation;

/**
 * Deterministic, explainable scorecard for healthcare businesses, standing in for the
 * AI-driven business-profile model a production system would call.
 *
 * The score starts at 600 and is adjusted by licence status, provider type, years trading,
 * debt service coverage and revenue scale, then clamped to 300–850. Every adjustment is
 * returned as a factor so officers can see why a decision was recommended.
 */
class MockHealthcareCreditScorer implements CreditBureau
{
    private const BASE_SCORE = 600;

    /** 1.50x: the business keeps at least a third of its cash flow after the repayment. */
    private const COMFORTABLE_DSCR_BPS = 15000;

    /** Below 1.20x a dip in sales would make the loan unserviceable. */
    private const MINIMUM_DSCR_BPS = 12000;

    private const DEFAULT_MINIMUM_SCORE = 560;

    public function assess(CreditApplicant $applicant): CreditDecision
    {
        $dscr = $applicant->debtServiceCoverageBps();

        $factors = [
            $this->licenceFactor($applicant->hasValidLicence),
            $this->businessTypeFactor($applicant->businessType),
            $this->tradingHistoryFactor($applicant->yearsInOperation),
            $this->debtServiceFactor($dscr),
            $this->revenueScaleFactor($applicant->monthlyRevenue),
            $this->exposureFactor($applicant->openLoanCount),
        ];

        $score = max(300, min(850, self::BASE_SCORE + array_sum(array_column($factors, 'impact'))));
        $minimumScore = $applicant->minimumScore ?? self::DEFAULT_MINIMUM_SCORE;

        $recommendation = match (true) {
            ! $applicant->hasValidLicence,
            $dscr < self::MINIMUM_DSCR_BPS,
            $score < $minimumScore => CreditRecommendation::Decline,
            $score >= 680 && $dscr >= self::COMFORTABLE_DSCR_BPS => CreditRecommendation::Approve,
            default => CreditRecommendation::Review,
        };

        return new CreditDecision(
            provider: 'mock-healthcare-scorer',
            score: $score,
            riskGrade: $this->grade($score),
            recommendation: $recommendation,
            debtServiceCoverageBps: $dscr,
            netMonthlyCashFlow: $applicant->netMonthlyCashFlow(),
            maxRecommendedAmount: $this->maxRecommendedAmount($applicant),
            factors: $factors,
        );
    }

    /**
     * Scale the requested amount so the monthly repayment keeps coverage at 1.50x.
     */
    private function maxRecommendedAmount(CreditApplicant $applicant): int
    {
        $obligation = $applicant->monthlyObligation();
        $cashFlow = $applicant->netMonthlyCashFlow();

        if ($obligation <= 0 || $cashFlow <= 0) {
            return 0;
        }

        $affordableObligation = intdiv($cashFlow * 10000, self::COMFORTABLE_DSCR_BPS);

        return intdiv($applicant->requestedAmount * $affordableObligation, $obligation);
    }

    /**
     * @return array{factor: string, impact: int, detail: string}
     */
    private function licenceFactor(bool $valid): array
    {
        return ['factor' => 'operating_licence', 'impact' => $valid ? 40 : -200, 'detail' => $valid ? 'valid' : 'expired'];
    }

    /**
     * @return array{factor: string, impact: int, detail: string}
     */
    private function businessTypeFactor(BusinessType $type): array
    {
        $impact = match ($type) {
            BusinessType::Hospital => 30,
            BusinessType::Pharmacy, BusinessType::Clinic => 20,
            BusinessType::Laboratory, BusinessType::DiagnosticCentre => 10,
            BusinessType::PatentMedicineStore => 0,
        };

        return ['factor' => 'business_type', 'impact' => $impact, 'detail' => $type->value];
    }

    /**
     * @return array{factor: string, impact: int, detail: string}
     */
    private function tradingHistoryFactor(int $years): array
    {
        $impact = match (true) {
            $years < 1 => -60,
            $years < 3 => -10,
            $years <= 5 => 30,
            default => 60,
        };

        return ['factor' => 'years_in_operation', 'impact' => $impact, 'detail' => (string) $years];
    }

    /**
     * @return array{factor: string, impact: int, detail: string}
     */
    private function debtServiceFactor(int $dscrBps): array
    {
        $impact = match (true) {
            $dscrBps >= 20000 => 80,
            $dscrBps >= self::COMFORTABLE_DSCR_BPS => 30,
            $dscrBps >= self::MINIMUM_DSCR_BPS => -40,
            default => -150,
        };

        return ['factor' => 'debt_service_coverage', 'impact' => $impact, 'detail' => sprintf('%.2fx', $dscrBps / 10000)];
    }

    /**
     * @return array{factor: string, impact: int, detail: string}
     */
    private function revenueScaleFactor(int $monthlyRevenue): array
    {
        $impact = match (true) {
            $monthlyRevenue >= 500_000_000 => 30,
            $monthlyRevenue >= 100_000_000 => 10,
            default => -20,
        };

        return ['factor' => 'monthly_revenue', 'impact' => $impact, 'detail' => (string) $monthlyRevenue];
    }

    /**
     * @return array{factor: string, impact: int, detail: string}
     */
    private function exposureFactor(int $openLoans): array
    {
        return ['factor' => 'open_loans', 'impact' => -min(120, $openLoans * 40), 'detail' => (string) $openLoans];
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 750 => 'A',
            $score >= 680 => 'B',
            $score >= 620 => 'C',
            $score >= 560 => 'D',
            default => 'E',
        };
    }
}
