<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\PlatformFeeBearer;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListPlatformFeesRequest;
use App\Http\Requests\UpdatePlatformFeeRequest;
use App\Http\Resources\InstitutionResource;
use App\Http\Resources\PlatformFeeResource;
use App\Models\Institution;
use App\Services\PlatformFeeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Platform
 */
class PlatformFeeController extends Controller
{
    public function __construct(private PlatformFeeService $platformFees) {}

    /**
     * Set a lender's platform fee.
     *
     * 10MG's fee for this lender: a rate in basis points of the principal and who bears it. Applies to
     * applications made from now on; existing loans keep the terms they were priced with.
     */
    public function update(UpdatePlatformFeeRequest $request, Institution $institution): InstitutionResource
    {
        return new InstitutionResource($this->platformFees->configure(
            $institution,
            $request->integer('rate_bps'),
            PlatformFeeBearer::from($request->validated('bearer')),
        ));
    }

    /**
     * Platform fee revenue.
     *
     * Every platform fee accrued across lenders, newest first, with totals per bearer in `meta.summary`.
     */
    public function index(ListPlatformFeesRequest $request): AnonymousResourceCollection
    {
        ['fees' => $fees, 'summary' => $summary] = $this->platformFees->ledger($request->validated(), acrossTenants: true);

        return PlatformFeeResource::collection($fees)->additional(['meta' => ['summary' => $summary]]);
    }
}
