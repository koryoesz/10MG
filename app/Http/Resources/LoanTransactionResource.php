<?php

namespace App\Http\Resources;

use App\Models\LoanTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanTransaction
 */
class LoanTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'external_reference' => $this->external_reference,
            'channel' => $this->channel,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'allocation' => $this->allocation,
            'performed_by' => $this->performed_by,
            'created_at' => $this->created_at,
        ];
    }
}
