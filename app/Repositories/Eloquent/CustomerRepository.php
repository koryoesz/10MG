<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return Customer::query()
            ->with('user')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('business_name', 'like', $term)
                    ->orWhere('registration_number', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($filters['business_type'] ?? null, fn (Builder $query, string $type) => $query->where('business_type', $type))
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->where('state', $state))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    public function create(User $owner, array $attributes): Customer
    {
        $customer = new Customer($attributes);
        $customer->user_id = $owner->getKey();
        $customer->save();

        return $customer->setRelation('user', $owner);
    }

    public function findForUser(User $user): Customer
    {
        return $user->customer()->firstOrFail();
    }

    public function lockForUser(User $user): Customer
    {
        return $user->customer()->lockForUpdate()->firstOrFail();
    }

    public function lockForUpdate(Customer $customer): Customer
    {
        return Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
    }

    public function save(Customer $customer): Customer
    {
        $customer->save();

        return $customer;
    }
}
