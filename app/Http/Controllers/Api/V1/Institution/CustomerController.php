<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCustomersRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\LoanResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Customers
 */
class CustomerController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    /**
     * List borrowing businesses.
     *
     * Search by business name, CAC number or phone; filter by `business_type` or `state`.
     */
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->customers->paginate($request->validated()));
    }

    /**
     * Show a borrowing business.
     */
    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->show($customer));
    }

    /**
     * A borrower's loan history.
     *
     * Newest first, with each loan's credit assessment.
     */
    public function loans(Customer $customer): AnonymousResourceCollection
    {
        return LoanResource::collection($this->customers->loanHistory($customer));
    }
}
