<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Enums\LoanPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyForLoanRequest;
use App\Http\Requests\ListLoansRequest;
use App\Http\Resources\LoanInstallmentResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanTransactionResource;
use App\Models\Loan;
use App\Services\Loans\LoanApplicationService;
use App\Services\Loans\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff see every loan in their institution; borrowers only their own.
 *
 * @tags Loans
 */
class LoanController extends Controller
{
    public function __construct(private LoanService $loans) {}

    /**
     * List loans.
     */
    public function index(ListLoansRequest $request): AnonymousResourceCollection
    {
        return LoanResource::collection($this->loans->listFor($request->user(), $request->validated()));
    }

    /**
     * Apply for a loan.
     *
     * Borrower-only. Validates the request against the product (limits and eligible business types),
     * snapshots its terms, scores the business profile and creates the loan in `pending`.
     * Requires an `Idempotency-Key` header.
     */
    public function store(ApplyForLoanRequest $request, LoanApplicationService $applications): JsonResponse
    {
        $loan = $applications->applyAs(
            owner: $request->user(),
            productId: $request->validated('loan_product_id'),
            amount: $request->integer('amount'),
            tenor: $request->integer('tenor'),
            purpose: LoanPurpose::from($request->validated('purpose')),
            purposeNote: $request->validated('purpose_note'),
        );

        return (new LoanResource($loan))->response()->setStatusCode(201);
    }

    /**
     * Show a loan.
     */
    public function show(Loan $loan): LoanResource
    {
        return new LoanResource($this->loans->detail($loan));
    }

    /**
     * Repayment schedule.
     */
    public function schedule(Loan $loan): AnonymousResourceCollection
    {
        return LoanInstallmentResource::collection($this->loans->schedule($loan));
    }

    /**
     * Ledger transactions.
     */
    public function transactions(Loan $loan): AnonymousResourceCollection
    {
        return LoanTransactionResource::collection($this->loans->transactions($loan));
    }
}
