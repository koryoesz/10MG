<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBusinessProfileRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Illuminate\Http\Request;

/**
 * @tags Borrower self-service
 */
class BusinessProfileController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    /**
     * My business profile.
     */
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($this->customers->profileOf($request->user()));
    }

    /**
     * Update my business profile.
     *
     * Refresh revenue, expenses, staff count and location, or renew the operating licence. New figures
     * are used by the credit scorer on the next application; existing assessments are unchanged.
     */
    public function update(UpdateBusinessProfileRequest $request): CustomerResource
    {
        return new CustomerResource($this->customers->updateProfile($request->user(), $request->validated()));
    }
}
