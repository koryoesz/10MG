<?php

namespace App\Http\Resources;

use App\Models\LoanProductFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanProductFee
 */
class LoanProductFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'calculation' => $this->calculation,
            'value' => $this->value,
            'charge_type' => $this->charge_type,
        ];
    }
}
