<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;
use App\Models\CustomerDirector;

interface CustomerDirectorRepositoryInterface
{
    /**
     * The business's owner record, or a new unsaved one linked to the business.
     */
    public function ownerFor(Customer $customer): CustomerDirector;

    /**
     * Whether a BVN or NIN is already linked to a different business in the current tenant.
     *
     * @param  'bvn'|'nin'  $type
     */
    public function isLinkedToAnotherCustomer(string $type, string $number, Customer $customer): bool;

    public function save(CustomerDirector $director): CustomerDirector;
}
