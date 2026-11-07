<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanProductFeeRequest;
use App\Http\Resources\LoanProductFeeResource;
use App\Models\LoanProduct;
use App\Models\LoanProductFee;
use App\Services\LoanProductService;
use Illuminate\Http\JsonResponse;

/**
 * @tags White-label configuration
 */
class LoanProductFeeController extends Controller
{
    public function __construct(private LoanProductService $products) {}

    /**
     * Add a fee to a product.
     *
     * `upfront` fees are deducted from the disbursed amount; `capitalized` fees are added to the
     * amount repayable and spread across installments.
     */
    public function store(StoreLoanProductFeeRequest $request, LoanProduct $loanProduct): JsonResponse
    {
        return (new LoanProductFeeResource($this->products->addFee($loanProduct, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove a fee from a product.
     */
    public function destroy(LoanProduct $loanProduct, LoanProductFee $fee): JsonResponse
    {
        $this->products->removeFee($loanProduct, $fee);

        return response()->json(null, 204);
    }
}
