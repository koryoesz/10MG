<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Borrowing healthcare businesses: onboarding, self-service profile and staff views.
 */
class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $customers,
        private UserRepositoryInterface $users,
        private LoanRepositoryInterface $loans,
        private TenantContext $tenant,
        private AuthService $auth,
        private AuditLogger $audit,
    ) {}

    /**
     * Onboard a healthcare business and its owner's login in the current tenant.
     *
     * @param  array<string, mixed>  $data  Validated registration payload.
     * @return array{customer: Customer, token: string}
     */
    public function register(array $data): array
    {
        $institution = $this->tenant->require();

        $customer = DB::transaction(function () use ($institution, $data): Customer {
            $owner = $this->users->create(
                ['name' => $data['contact_name'], 'email' => $data['email'], 'password' => $data['password']],
                $institution->id,
                Role::Customer,
            );

            $customer = $this->customers->create($owner, array_diff_key($data, array_flip(['contact_name', 'email', 'password'])));

            $this->audit->record('customer.registered', $customer, newValues: [
                'business_name' => $customer->business_name,
                'business_type' => $customer->business_type->value,
                'registration_number' => $customer->registration_number,
                'contact_email' => $owner->email,
            ], actor: $owner);

            return $customer;
        });

        return ['customer' => $customer, 'token' => $this->auth->issueToken($customer->user)];
    }

    /**
     * @param  array{search?: string, business_type?: string, state?: string}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->customers->paginate($filters);
    }

    public function show(Customer $customer): Customer
    {
        return $customer->load('user');
    }

    /**
     * @return LengthAwarePaginator<int, Loan>
     */
    public function loanHistory(Customer $customer): LengthAwarePaginator
    {
        return $this->loans->paginate([], $customer);
    }

    public function profileOf(User $owner): Customer
    {
        return $this->customers->findForUser($owner)->load('user');
    }

    /**
     * Borrower self-service update of trading figures, location and licence renewal.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(User $owner, array $attributes): Customer
    {
        $customer = DB::transaction(function () use ($owner, $attributes): Customer {
            $customer = $this->customers->lockForUser($owner)->fill($attributes);
            $this->audit->recordChanges('customer.profile_updated', $customer);

            return $this->customers->save($customer);
        });

        return $customer->load('user');
    }
}
