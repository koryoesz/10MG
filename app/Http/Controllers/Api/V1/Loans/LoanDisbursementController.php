<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\Loans\DisbursementService;
use Illuminate\Http\Request;

/**
 * @tags Loans
 */
class LoanDisbursementController extends Controller
{
    public function __construct(private DisbursementService $disbursements) {}

    /**
     * Disburse a loan.
     *
     * `approved` → `disbursed`. Sends the net amount (principal minus upfront fees) through the
     * (mock) payment rail, generates the repayment schedule and posts ledger entries, all in one
     * transaction. The approver cannot also disburse. Requires an `Idempotency-Key` header.
     */
    public function store(Request $request, Loan $loan): LoanResource
    {
        return new LoanResource($this->disbursements->disburse($loan, $request->user()));
    }
}
