<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;

/**
 * @tags Onboarding
 */
class CustomerRegistrationController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    /**
     * Register a healthcare business.
     *
     * Onboards a pharmacy, clinic, hospital, laboratory or diagnostic centre as a borrower of the
     * institution named by `X-Tenant`, together with the owner's login, and returns a bearer token.
     *
     * @unauthenticated
     */
    public function store(RegisterCustomerRequest $request): JsonResponse
    {
        $registration = $this->customers->register($request->validated());

        return response()->json([
            'data' => [
                'customer' => new CustomerResource($registration['customer']),
                'token' => $registration['token'],
                'token_type' => 'Bearer',
            ],
        ], 201);
    }
}
