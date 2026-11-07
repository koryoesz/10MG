<?php

namespace App\Http\Resources;

use App\Models\LoanInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanInstallment
 */
class LoanInstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sequence' => $this->sequence,
            'due_date' => $this->due_date->toDateString(),
            'principal_due' => $this->principal_due,
            'interest_due' => $this->interest_due,
            'fee_due' => $this->fee_due,
            'penalty_due' => $this->penalty_due,
            'total_due' => $this->total_due,
            'amount_paid' => $this->amount_paid,
            'outstanding' => $this->outstanding(),
            'status' => $this->status,
            'paid_at' => $this->paid_at,
        ];
    }
}
