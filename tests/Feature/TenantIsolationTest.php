<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\Api\TenantContextMissing;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\InstitutionBranding;
use App\Models\LoanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $alpha;

    private Institution $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Institution::factory()->create(['slug' => 'alpha']);
        $this->beta = Institution::factory()->create(['slug' => 'beta']);
    }

    public function test_staff_cannot_read_another_institutions_loan_and_get_404(): void
    {
        $betaLoan = $this->loanFor($this->customer($this->beta), $this->product($this->beta));

        $this->as($this->staff($this->alpha, Role::InstitutionAdmin))
            ->getJson("/api/v1/loans/{$betaLoan->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_staff_cannot_act_on_another_institutions_loan_and_get_404(): void
    {
        $betaLoan = $this->loanFor($this->customer($this->beta), $this->product($this->beta));

        $this->as($this->staff($this->alpha, Role::InstitutionAdmin))
            ->idempotentPost("/api/v1/loans/{$betaLoan->id}/approve")
            ->assertNotFound();

        $this->assertSame('pending', $betaLoan->fresh()->status->value);
    }

    public function test_loan_listing_only_contains_the_callers_institution(): void
    {
        $alphaLoan = $this->loanFor($this->customer($this->alpha), $this->product($this->alpha));
        $this->loanFor($this->customer($this->beta), $this->product($this->beta));

        $this->as($this->staff($this->alpha, Role::LoanOfficer))
            ->getJson('/api/v1/loans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alphaLoan->id);
    }

    public function test_x_tenant_header_cannot_override_the_tokens_institution_and_returns_403(): void
    {
        $this->as($this->staff($this->alpha, Role::InstitutionAdmin))
            ->getJson('/api/v1/loan-products', ['X-Tenant' => 'beta'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_MISMATCH');
    }

    public function test_customer_cannot_apply_with_another_institutions_product(): void
    {
        $customer = $this->customer($this->alpha);
        $betaProduct = $this->product($this->beta);

        $this->as($customer->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $betaProduct->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('loan_product_id', 'error.details');
    }

    public function test_cannot_read_or_edit_another_institutions_product_or_customer(): void
    {
        $betaProduct = $this->product($this->beta);
        $betaCustomer = $this->customer($this->beta);

        $this->as($this->staff($this->alpha, Role::InstitutionAdmin));

        $this->getJson("/api/v1/loan-products/{$betaProduct->id}")->assertNotFound();
        $this->patchJson("/api/v1/loan-products/{$betaProduct->id}", ['interest_rate_bps' => 1])->assertNotFound();
        $this->getJson("/api/v1/customers/{$betaCustomer->id}")->assertNotFound();
        $this->assertSame(2400, $betaProduct->fresh()->interest_rate_bps);
    }

    public function test_cannot_manage_another_institutions_staff(): void
    {
        $betaOfficer = $this->staff($this->beta, Role::LoanOfficer);

        $this->as($this->staff($this->alpha, Role::InstitutionAdmin))
            ->patchJson("/api/v1/staff/{$betaOfficer->id}", ['is_active' => false])
            ->assertNotFound();

        $this->assertTrue($betaOfficer->fresh()->is_active);
    }

    public function test_public_storefront_serves_each_institutions_own_branding_and_products(): void
    {
        $this->unscoped(InstitutionBranding::class)->where('institution_id', $this->alpha->id)->update(['primary_color' => '#111111']);
        $this->unscoped(InstitutionBranding::class)->where('institution_id', $this->beta->id)->update(['primary_color' => '#222222']);
        $this->product($this->alpha, ['name' => 'Alpha Cash']);
        $this->product($this->beta, ['name' => 'Beta Boost']);

        $this->getJson('/api/v1/tenant', ['X-Tenant' => 'alpha'])->assertJsonPath('data.branding.primary_color', '#111111');
        $this->getJson('/api/v1/tenant', ['X-Tenant' => 'beta'])->assertJsonPath('data.branding.primary_color', '#222222');
        $this->getJson('/api/v1/tenant/loan-products', ['X-Tenant' => 'beta'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Boost');
    }

    public function test_tenant_routes_without_tenant_context_return_400(): void
    {
        $this->getJson('/api/v1/tenant')
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }

    public function test_tenant_scoped_queries_match_nothing_without_a_tenant(): void
    {
        $this->product($this->alpha);

        $this->assertSame(0, LoanProduct::query()->count());
        $this->assertSame(1, $this->unscoped(LoanProduct::class)->count());
    }

    public function test_creating_tenant_data_without_a_tenant_is_refused(): void
    {
        $this->expectException(TenantContextMissing::class);

        (new LoanProduct)->forceFill(['name' => 'Orphan'])->save();
    }

    public function test_borrower_cannot_widen_their_loan_list_with_a_customer_filter(): void
    {
        $product = $this->product($this->alpha);
        $mine = $this->customer($this->alpha);
        $theirs = $this->customer($this->alpha);
        $this->loanFor($theirs, $product);

        $this->as($mine->user)->getJson("/api/v1/loans?customer_id={$theirs->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_borrower_search_treats_wildcards_literally(): void
    {
        $this->customer($this->alpha, ['business_name' => 'Okafor Pharmacy']);
        $this->as($this->staff($this->alpha, Role::LoanOfficer));

        $this->getJson('/api/v1/customers?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customers?search=Okafor')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_customer_registration_is_unique_per_institution_not_globally(): void
    {
        $payload = [
            'contact_name' => 'Ada Obi', 'email' => 'ada@obipharmacy.test', 'password' => 'Secret123',
            'business_name' => 'Obi Pharmacy', 'business_type' => 'pharmacy', 'registration_number' => 'rc1234567',
            'licence_number' => 'PCN/PR/1001', 'licence_expires_at' => now()->addYear()->toDateString(),
            'year_established' => 2019, 'phone' => '+2348000000001', 'city' => 'Yaba', 'state' => 'Lagos',
            'monthly_revenue' => 300_000_000, 'monthly_expenses' => 200_000_000,
        ];

        $this->postJson('/api/v1/tenant/customers', $payload, ['X-Tenant' => 'alpha'])->assertCreated();
        $this->postJson('/api/v1/tenant/customers', $payload, ['X-Tenant' => 'beta'])->assertCreated();
        $this->postJson('/api/v1/tenant/customers', $payload, ['X-Tenant' => 'alpha'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('email', 'error.details')
            ->assertJsonValidationErrorFor('registration_number', 'error.details')
            ->assertJsonValidationErrorFor('phone', 'error.details');

        $this->assertSame(2, $this->unscoped(Customer::class)->count());
    }
}
