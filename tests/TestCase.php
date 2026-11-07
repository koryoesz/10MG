<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate subsequent requests as the given user.
     */
    protected function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * POST with a fresh (or given) Idempotency-Key.
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<JsonResponse>
     */
    protected function idempotentPost(string $uri, array $data = [], ?string $key = null): TestResponse
    {
        return $this->postJson($uri, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function staff(Institution $institution, Role $role): User
    {
        return User::factory()->forInstitution($institution)->role($role)->create();
    }

    protected function customer(Institution $institution, array $attributes = []): Customer
    {
        return Customer::factory()->forInstitution($institution)->create($attributes);
    }

    protected function product(Institution $institution, array $attributes = []): LoanProduct
    {
        return LoanProduct::factory()->forInstitution($institution)->create($attributes);
    }

    /**
     * Read a tenant-owned model outside of any request, bypassing the tenant scope.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    protected function unscoped(string $model): Builder
    {
        return $model::query()->withoutGlobalScopes();
    }

    /**
     * Drive a loan through the API to the requested point of its lifecycle.
     */
    protected function loanFor(Customer $customer, LoanProduct $product, string $until = 'pending', int $amount = 10_000_000, int $tenor = 6): Loan
    {
        $institution = $customer->institution()->first();

        $loanId = $this->as($customer->user()->first())
            ->idempotentPost('/api/v1/loans', [
                'loan_product_id' => $product->id,
                'purpose' => 'inventory_restock',
                'amount' => $amount,
                'tenor' => $tenor,
            ])
            ->assertCreated()
            ->json('data.id');

        if (in_array($until, ['approved', 'disbursed'], true)) {
            $this->as($this->staff($institution, Role::LoanOfficer))
                ->idempotentPost("/api/v1/loans/{$loanId}/approve", ['override_reason' => 'Approved for test scenario'])
                ->assertOk();
        }

        if ($until === 'disbursed') {
            $this->as($this->staff($institution, Role::FinanceOfficer))
                ->idempotentPost("/api/v1/loans/{$loanId}/disburse")
                ->assertOk();
        }

        app(TenantContext::class)->forget();

        return $this->unscoped(Loan::class)->findOrFail($loanId);
    }
}
