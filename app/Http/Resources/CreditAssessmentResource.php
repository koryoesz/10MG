<?php

namespace App\Http\Resources;

use App\Models\CreditAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditAssessment
 */
class CreditAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->provider,
            'score' => $this->score,
            'risk_grade' => $this->risk_grade,
            'recommendation' => $this->recommendation,
            'debt_service_coverage_bps' => $this->debt_service_coverage_bps,
            'net_monthly_cash_flow' => $this->net_monthly_cash_flow,
            'max_recommended_amount' => $this->max_recommended_amount,
            'factors' => $this->factors,
            'assessed_at' => $this->assessed_at,
        ];
    }
}
