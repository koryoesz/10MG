<?php

namespace App\Services\Credit;

use App\Enums\CreditRecommendation;

final readonly class CreditDecision
{
    /**
     * @param  list<array{factor: string, impact: int, detail: string}>  $factors
     */
    public function __construct(
        public string $provider,
        public int $score,
        public string $riskGrade,
        public CreditRecommendation $recommendation,
        public int $debtServiceCoverageBps,
        public int $netMonthlyCashFlow,
        public int $maxRecommendedAmount,
        public array $factors,
    ) {}
}
