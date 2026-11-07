<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListLoanProductsRequest;
use App\Http\Requests\LoanProductRequest;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use App\Services\LoanProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Loan products define the institution's pricing: limits, interest, fees and repayment rules.
 * Amounts are in minor units and rates in basis points. Editing a product never changes
 * existing loans, which keep a snapshot of the terms they were priced with.
 *
 * @tags White-label configuration
 */
class LoanProductController extends Controller
{
    public function __construct(private LoanProductService $products) {}

    /**
     * List loan products.
     */
    public function index(ListLoanProductsRequest $request): AnonymousResourceCollection
    {
        return LoanProductResource::collection($this->products->list($request->validated()));
    }

    /**
     * Create a loan product.
     */
    public function store(LoanProductRequest $request): JsonResponse
    {
        return (new LoanProductResource($this->products->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Show a loan product.
     */
    public function show(LoanProduct $loanProduct): LoanProductResource
    {
        return new LoanProductResource($this->products->show($loanProduct));
    }

    /**
     * Update a loan product.
     */
    public function update(LoanProductRequest $request, LoanProduct $loanProduct): LoanProductResource
    {
        return new LoanProductResource($this->products->update($loanProduct, $request->validated()));
    }

    /**
     * Archive a loan product.
     *
     * Soft-deletes the product. Existing loans are unaffected.
     */
    public function destroy(LoanProduct $loanProduct): JsonResponse
    {
        $this->products->archive($loanProduct);

        return response()->json(null, 204);
    }
}
