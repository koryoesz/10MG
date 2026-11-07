<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordRepaymentRequest;
use App\Http\Resources\RepaymentResource;
use App\Models\Loan;
use App\Services\Loans\RepaymentService;
use Illuminate\Http\JsonResponse;

/**
 * @tags Loans
 */
class LoanRepaymentController extends Controller
{
    public function __construct(private RepaymentService $repayments) {}

    /**
     * Record a repayment.
     *
     * Staff record payments received; borrowers can pay their own loan (electronic channels only).
     * The amount is allocated oldest installment first (penalty → fees → interest → principal).
     * Overpayment is rejected and an `external_reference` can only be applied once.
     * Requires an `Idempotency-Key` header.
     */
    public function store(RecordRepaymentRequest $request, Loan $loan): JsonResponse
    {
        $transaction = $this->repayments->record(
            loan: $loan,
            actor: $request->user(),
            amount: $request->integer('amount'),
            channel: $request->validated('channel'),
            externalReference: $request->validated('external_reference'),
        );

        return (new RepaymentResource($transaction))->response()->setStatusCode(201);
    }
}
