<?php

namespace App\Repositories\Eloquent;

use App\Enums\DirectorRole;
use App\Models\Customer;
use App\Models\CustomerDirector;
use App\Repositories\Contracts\CustomerDirectorRepositoryInterface;

class CustomerDirectorRepository implements CustomerDirectorRepositoryInterface
{
    public function ownerFor(Customer $customer): CustomerDirector
    {
        $owner = $customer->owner()->first() ?? new CustomerDirector(['role' => DirectorRole::Owner]);
        $owner->customer_id = $customer->getKey();

        return $owner->setRelation('customer', $customer);
    }

    public function isLinkedToAnotherCustomer(string $type, string $number, Customer $customer): bool
    {
        return CustomerDirector::query()
            ->where("{$type}_hash", CustomerDirector::fingerprint($number))
            ->where('customer_id', '!=', $customer->getKey())
            ->exists();
    }

    public function save(CustomerDirector $director): CustomerDirector
    {
        $director->save();

        return $director;
    }
}
