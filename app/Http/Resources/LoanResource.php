<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * All amounts are integers in minor units of `currency`; rates are basis points.
 *
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'loan_product_id' => $this->loan_product_id,
            'currency' => $this->currency,
            'principal_amount' => $this->principal_amount,
            'tenor' => $this->tenor,
            'interest_rate_bps' => $this->interest_rate_bps,
            'interest_method' => $this->interest_method,
            'repayment_frequency' => $this->repayment_frequency,
            'fees' => $this->fees_snapshot,
            'total_interest' => $this->total_interest,
            'upfront_fees' => $this->upfront_fees,
            'capitalized_fees' => $this->capitalized_fees,
            'penalty_fees' => $this->penalty_fees,
            /** 10MG's fee on this loan. Shown to lender staff; borrowers see it in `fees` only when they bear it. */
            'platform_fee' => $this->when($request->user()?->role !== Role::Customer, fn (): array => [
                'rate_bps' => $this->platform_fee_bps,
                'bearer' => $this->platform_fee_bearer,
                'amount' => $this->platform_fee_amount,
            ]),
            'net_disbursement_amount' => $this->net_disbursement_amount,
            'total_repayable' => $this->total_repayable,
            'amount_paid' => $this->amount_paid,
            'outstanding_balance' => $this->outstanding_balance,
            'purpose' => $this->purpose,
            'purpose_note' => $this->purpose_note,
            'decision_reason' => $this->decision_reason,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'disbursed_by' => $this->disbursed_by,
            'disbursed_at' => $this->disbursed_at,
            'disbursement_reference' => $this->disbursement_reference,
            'first_due_date' => $this->first_due_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),
            'closed_at' => $this->closed_at,
            'credit_assessment' => new CreditAssessmentResource($this->whenLoaded('creditAssessment')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'product' => new LoanProductResource($this->whenLoaded('product')),
            'installments' => LoanInstallmentResource::collection($this->whenLoaded('installments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
