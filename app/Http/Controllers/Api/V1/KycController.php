<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitKycRequest;
use App\Http\Resources\KycResource;
use App\Services\KycService;
use Illuminate\Http\Request;

/**
 * @tags Borrower self-service
 */
class KycController extends Controller
{
    public function __construct(private KycService $kyc) {}

    /**
     * My KYC status.
     */
    public function show(Request $request): KycResource
    {
        return new KycResource($this->kyc->statusOf($request->user()));
    }

    /**
     * Verify the business owner's BVN and NIN.
     *
     * The BVN is matched with NIBSS and the NIN with NIMC. Both must match on name and date of
     * birth before the business can apply for a loan. A failed attempt is recorded and can be
     * retried with corrected details (422 `KYC_VERIFICATION_FAILED`). Requires an `Idempotency-Key`,
     * because every attempt is a billable provider lookup.
     */
    public function store(SubmitKycRequest $request): KycResource
    {
        return new KycResource($this->kyc->submit($request->user(), $request->validated()));
    }
}
