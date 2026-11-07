<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface
{
    /**
     * @param  array{search?: string, business_type?: string, state?: string}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $owner, array $attributes): Customer;

    public function findForUser(User $user): Customer;

    public function lockForUser(User $user): Customer;

    public function lockForUpdate(Customer $customer): Customer;

    public function save(Customer $customer): Customer;
}
