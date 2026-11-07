<?php

namespace App\Http\Resources;

use App\Models\LoanProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanProduct
 */
class LoanProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'currency' => $this->currency,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'min_tenor' => $this->min_tenor,
            'max_tenor' => $this->max_tenor,
            'interest_rate_bps' => $this->interest_rate_bps,
            'interest_method' => $this->interest_method,
            'repayment_frequency' => $this->repayment_frequency,
            'grace_period_days' => $this->grace_period_days,
            'late_fee_type' => $this->late_fee_type,
            'late_fee_value' => $this->late_fee_value,
            'min_credit_score' => $this->min_credit_score,
            'eligible_business_types' => $this->eligible_business_types,
            'is_active' => $this->is_active,
            'fees' => LoanProductFeeResource::collection($this->whenLoaded('fees')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
