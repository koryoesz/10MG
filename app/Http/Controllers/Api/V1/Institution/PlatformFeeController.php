<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPlatformFeesRequest;
use App\Http\Resources\PlatformFeeResource;
use App\Services\PlatformFeeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags White-label configuration
 */
class PlatformFeeController extends Controller
{
    public function __construct(private PlatformFeeService $platformFees) {}

    /**
     * Platform fees owed to 10MG.
     *
     * This lender's current platform fee terms (`meta.terms`) and the fee on each disbursed loan, with totals.
     */
    public function index(ListPlatformFeesRequest $request, TenantContext $tenant): AnonymousResourceCollection
    {
        ['fees' => $fees, 'summary' => $summary] = $this->platformFees->ledger($request->safe()->except('institution_id'), acrossTenants: false);

        return PlatformFeeResource::collection($fees)->additional(['meta' => [
            'terms' => $this->platformFees->termsOf($tenant->require()),
            'summary' => $summary,
        ]]);
    }
}
