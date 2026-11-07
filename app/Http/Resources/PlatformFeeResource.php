<?php

namespace App\Http\Resources;

use App\Models\PlatformFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 10MG's fee on one disbursed loan.
 *
 * @mixin PlatformFee
 */
class PlatformFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'loan_id' => $this->loan_id,
            'loan_reference' => $this->whenLoaded('loan', fn () => $this->loan?->reference),
            'bearer' => $this->bearer,
            'rate_bps' => $this->rate_bps,
            'base_amount' => $this->base_amount,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'accrued_at' => $this->accrued_at,
        ];
    }
}
