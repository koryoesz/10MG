<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteLoanRequest;
use App\Http\Resources\LoanProductResource;
use App\Http\Resources\StorefrontResource;
use App\Models\LoanProduct;
use App\Services\InstitutionService;
use App\Services\LoanProductService;
use App\Services\Loans\LoanPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, white-labelled storefront data for one institution, selected by `X-Tenant`.
 *
 * @tags White-label storefront
 */
class TenantCatalogueController extends Controller
{
    /**
     * Institution profile & branding.
     *
     * @unauthenticated
     */
    public function show(InstitutionService $institutions): StorefrontResource
    {
        return new StorefrontResource($institutions->storefrontProfile());
    }

    /**
     * Active loan products.
     *
     * @unauthenticated
     */
    public function products(LoanProductService $products): AnonymousResourceCollection
    {
        return LoanProductResource::collection($products->catalogue());
    }

    /**
     * Quote a loan.
     *
     * Prices the requested amount and tenor against the product, returning the totals and the
     * full projected repayment schedule. Nothing is persisted.
     *
     * @unauthenticated
     */
    public function quote(QuoteLoanRequest $request, LoanProduct $loanProduct, LoanPricingService $pricing): JsonResponse
    {
        $quote = $pricing->quoteForSale($loanProduct, $request->integer('amount'), $request->integer('tenor'));

        return response()->json(['data' => ['currency' => $loanProduct->currency] + $quote->toArray()]);
    }
}
