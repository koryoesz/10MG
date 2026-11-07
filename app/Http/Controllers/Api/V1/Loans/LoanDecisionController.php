<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\CancelLoanRequest;
use App\Http\Requests\RejectLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\Loans\LoanDecisionService;

/**
 * @tags Loans
 */
class LoanDecisionController extends Controller
{
    public function __construct(private LoanDecisionService $decisions) {}

    /**
     * Approve a loan.
     *
     * `pending` → `approved`. If the credit assessment recommended `decline`, an
     * `override_reason` is mandatory. Requires an `Idempotency-Key` header.
     */
    public function approve(ApproveLoanRequest $request, Loan $loan): LoanResource
    {
        return new LoanResource($this->decisions->approve($loan, $request->user(), $request->validated('note'), $request->validated('override_reason')));
    }

    /**
     * Reject a loan.
     *
     * `pending` → `rejected`. Requires an `Idempotency-Key` header.
     */
    public function reject(RejectLoanRequest $request, Loan $loan): LoanResource
    {
        return new LoanResource($this->decisions->reject($loan, $request->user(), $request->validated('reason')));
    }

    /**
     * Cancel a loan.
     *
     * Borrowers can cancel their own loan before disbursement. Requires an `Idempotency-Key` header.
     */
    public function cancel(CancelLoanRequest $request, Loan $loan): LoanResource
    {
        return new LoanResource($this->decisions->cancel($loan, $request->user(), $request->validated('reason')));
    }
}
