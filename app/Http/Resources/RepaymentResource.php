<?php

namespace App\Http\Resources;

use App\Models\LoanTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recorded repayment together with the loan's updated state.
 *
 * @mixin LoanTransaction
 */
class RepaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction' => new LoanTransactionResource($this->resource),
            'loan' => new LoanResource($this->loan),
        ];
    }
}
