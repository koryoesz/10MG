<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhiteLabelConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->admin = $this->staff($this->institution, Role::InstitutionAdmin);
    }

    public function test_admin_updates_branding_and_the_change_is_audited_with_a_diff(): void
    {
        $this->as($this->admin)->patchJson('/api/v1/branding', [
            'display_name' => 'Ikeja Cash',
            'primary_color' => '#FF0000',
            'logo_url' => 'https://cdn.example.test/logo.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Ikeja Cash')
            ->assertJsonPath('data.primary_color', '#FF0000');

        $this->getJson('/api/v1/tenant', ['X-Tenant' => $this->institution->slug])
            ->assertJsonPath('data.branding.display_name', 'Ikeja Cash');

        $this->assertDatabaseHas('audit_logs', ['action' => 'branding.updated', 'user_id' => $this->admin->id]);
        $this->as($this->admin)->getJson('/api/v1/audit-logs?action=branding.updated')
            ->assertJsonPath('data.0.old_values.primary_color', '#0F172A')
            ->assertJsonPath('data.0.new_values.primary_color', '#FF0000');
    }

    public function test_branding_rejects_invalid_colours_and_insecure_logo_urls(): void
    {
        $this->as($this->admin)->patchJson('/api/v1/branding', ['primary_color' => 'red', 'logo_url' => 'http://insecure.test/logo.png'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('primary_color', 'error.details')
            ->assertJsonValidationErrorFor('logo_url', 'error.details');
    }

    public function test_custom_domain_must_be_unique_across_institutions(): void
    {
        $other = Institution::factory()->create();
        $this->as($this->staff($other, Role::InstitutionAdmin))->patchJson('/api/v1/branding', ['custom_domain' => 'loans.bank.test'])->assertOk();

        $this->as($this->admin)->patchJson('/api/v1/branding', ['custom_domain' => 'loans.bank.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('custom_domain', 'error.details');
    }

    public function test_admin_creates_a_product_with_fees_priced_in_the_institutions_currency(): void
    {
        $this->as($this->admin)->postJson('/api/v1/loan-products', $this->productPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'BIZ-90')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.interest_method', 'flat')
            ->assertJsonPath('data.fees.0.name', 'Processing fee')
            ->assertJsonPath('data.fees.0.charge_type', 'upfront');

        $this->assertDatabaseHas('audit_logs', ['action' => 'loan_product.created']);
    }

    public function test_product_limits_must_be_ordered(): void
    {
        $this->as($this->admin)->postJson('/api/v1/loan-products', $this->productPayload(['min_amount' => 500, 'max_amount' => 100, 'min_tenor' => 6, 'max_tenor' => 2]))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.max_amount.0', 'The max amount must be greater than or equal to the min amount.')
            ->assertJsonPath('error.details.max_tenor.0', 'The max tenor must be greater than or equal to the min tenor.');
    }

    public function test_partial_update_validates_against_the_existing_limits(): void
    {
        $product = $this->product($this->institution, ['min_amount' => 1_000_000]);

        $this->as($this->admin)->patchJson("/api/v1/loan-products/{$product->id}", ['max_amount' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('max_amount', 'error.details');
    }

    public function test_percentage_fees_cannot_exceed_100_percent(): void
    {
        $payload = $this->productPayload();
        $payload['fees'][0]['value'] = 10_001;

        $this->as($this->admin)->postJson('/api/v1/loan-products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('fees.0.value', 'error.details');
    }

    public function test_product_codes_are_unique_per_institution_only(): void
    {
        $this->product(Institution::factory()->create(), ['code' => 'BIZ-90']);
        $this->as($this->admin)->postJson('/api/v1/loan-products', $this->productPayload())->assertCreated();

        $this->as($this->admin)->postJson('/api/v1/loan-products', $this->productPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('code', 'error.details');
    }

    public function test_updating_a_product_may_keep_its_own_code(): void
    {
        $product = $this->product($this->institution, ['code' => 'KEEP-ME']);

        $this->as($this->admin)->patchJson("/api/v1/loan-products/{$product->id}", ['code' => 'KEEP-ME', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_repricing_a_product_does_not_change_existing_loans(): void
    {
        $product = $this->product($this->institution);
        $loan = $this->loanFor($this->customer($this->institution), $product);

        $this->as($this->admin)->patchJson("/api/v1/loan-products/{$product->id}", ['interest_rate_bps' => 9900])
            ->assertOk()
            ->assertJsonPath('data.interest_rate_bps', 9900);

        $snapshot = $this->unscoped(Loan::class)->find($loan->id);
        $this->assertSame(2400, $snapshot->interest_rate_bps);
        $this->assertSame($loan->total_interest, $snapshot->total_interest);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan_product.updated', 'auditable_id' => $product->id]);
    }

    public function test_fees_can_be_added_and_removed_and_change_new_quotes(): void
    {
        $product = $this->product($this->institution);
        $quote = fn () => $this->postJson("/api/v1/tenant/loan-products/{$product->id}/quote", ['amount' => 10_000_000, 'tenor' => 3], ['X-Tenant' => $this->institution->slug]);

        $this->assertSame(10_000_000, $quote()->json('data.net_disbursement_amount'));

        $fee = $this->as($this->admin)->postJson("/api/v1/loan-products/{$product->id}/fees", [
            'name' => 'Management fee', 'calculation' => 'percentage', 'value' => 250, 'charge_type' => 'upfront',
        ])->assertCreated()->json('data.id');

        $this->assertSame(9_750_000, $quote()->json('data.net_disbursement_amount'));

        $this->as($this->admin)->deleteJson("/api/v1/loan-products/{$product->id}/fees/{$fee}")->assertNoContent();
        $this->assertSame(10_000_000, $quote()->json('data.net_disbursement_amount'));
    }

    public function test_fee_must_belong_to_the_product_in_the_url(): void
    {
        $product = $this->product($this->institution);
        $otherProduct = LoanProduct::factory()->forInstitution($this->institution)->withStandardFees()->create();
        $otherFee = $otherProduct->fees()->withoutGlobalScopes()->first();

        $this->as($this->admin)->deleteJson("/api/v1/loan-products/{$product->id}/fees/{$otherFee->id}")->assertNotFound();
    }

    public function test_deactivating_a_product_blocks_new_applications_but_not_loans_in_flight(): void
    {
        $product = $this->product($this->institution);
        $inFlight = $this->loanFor($this->customer($this->institution), $product);

        $this->as($this->admin)->patchJson("/api/v1/loan-products/{$product->id}", ['is_active' => false, 'interest_rate_bps' => 9900])->assertOk();

        $this->as($this->customer($this->institution)->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOAN_TERMS_VIOLATION');

        $this->as($this->staff($this->institution, Role::LoanOfficer))
            ->idempotentPost("/api/v1/loans/{$inFlight->id}/approve")->assertOk();
        $this->as($this->staff($this->institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$inFlight->id}/disburse")
            ->assertOk()
            ->assertJsonPath('data.interest_rate_bps', 2400);
    }

    public function test_archived_products_disappear_from_the_storefront(): void
    {
        $product = $this->product($this->institution);

        $this->as($this->admin)->deleteJson("/api/v1/loan-products/{$product->id}")->assertNoContent();

        $this->getJson('/api/v1/tenant/loan-products', ['X-Tenant' => $this->institution->slug])->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('loan_products', ['id' => $product->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Business Loan 90',
            'code' => 'BIZ-90',
            'min_amount' => 10_000_000,
            'max_amount' => 500_000_000,
            'min_tenor' => 1,
            'max_tenor' => 3,
            'interest_rate_bps' => 4800,
            'interest_method' => 'flat',
            'repayment_frequency' => 'monthly',
            'grace_period_days' => 5,
            'late_fee_type' => 'fixed',
            'late_fee_value' => 250_000,
            'fees' => [
                ['name' => 'Processing fee', 'calculation' => 'percentage', 'value' => 150, 'charge_type' => 'upfront'],
            ],
        ];
    }
}
