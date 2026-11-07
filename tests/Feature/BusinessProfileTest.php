<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\LoanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Customer $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->pharmacy = Customer::factory()->forInstitution($this->institution)->create(['business_name' => 'Okafor Pharmacy']);
    }

    public function test_borrower_reads_their_own_business_profile(): void
    {
        $this->as($this->pharmacy->user)->getJson('/api/v1/me/business')
            ->assertOk()
            ->assertJsonPath('data.id', $this->pharmacy->id)
            ->assertJsonPath('data.business_name', 'Okafor Pharmacy')
            ->assertJsonPath('data.licence.regulator', 'Pharmacists Council of Nigeria (PCN)')
            ->assertJsonPath('data.net_monthly_cash_flow', 100_000_000);
    }

    public function test_borrower_updates_trading_figures_and_the_change_is_audited(): void
    {
        $this->as($this->pharmacy->user)->patchJson('/api/v1/me/business', [
            'monthly_revenue' => 450_000_000,
            'staff_count' => 12,
        ])
            ->assertOk()
            ->assertJsonPath('data.monthly_revenue', 450_000_000)
            ->assertJsonPath('data.staff_count', 12)
            ->assertJsonPath('data.net_monthly_cash_flow', 250_000_000);

        $entry = AuditLog::query()->where('action', 'customer.profile_updated')->sole();
        $this->assertSame($this->pharmacy->user_id, $entry->user_id);
        $this->assertEquals(['monthly_revenue' => 300_000_000, 'staff_count' => 8], $entry->old_values);
        $this->assertEquals(['monthly_revenue' => 450_000_000, 'staff_count' => 12], $entry->new_values);
    }

    public function test_renewed_licence_is_used_by_the_next_credit_assessment(): void
    {
        $expired = Customer::factory()->forInstitution($this->institution)->withExpiredLicence()->create();
        $product = LoanProduct::factory()->forInstitution($this->institution)->create();

        $this->as($expired->user)->patchJson('/api/v1/me/business', [
            'licence_number' => 'PCN/PR/99001',
            'licence_expires_at' => now()->addYears(2)->toDateString(),
        ])->assertOk()->assertJsonPath('data.licence.is_valid', true);

        $this->as($expired->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertCreated()
            ->assertJsonPath('data.credit_assessment.factors.0.detail', 'valid');
    }

    public function test_licence_renewal_needs_a_future_expiry_and_a_number(): void
    {
        $this->as($this->pharmacy->user);

        $this->patchJson('/api/v1/me/business', ['licence_number' => 'PCN/PR/1', 'licence_expires_at' => now()->subDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.licence_expires_at.0', 'The renewed licence must expire in the future.');

        $this->patchJson('/api/v1/me/business', ['licence_expires_at' => now()->addYear()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('licence_number', 'error.details');
    }

    public function test_legal_identity_cannot_be_changed_and_returns_422(): void
    {
        $this->as($this->pharmacy->user)->patchJson('/api/v1/me/business', [
            'registration_number' => 'RC0000001',
            'business_type' => 'hospital',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.registration_number.0', 'The registration number cannot be changed. Contact your lender to correct it.')
            ->assertJsonValidationErrorFor('business_type', 'error.details');

        $this->assertSame('pharmacy', $this->pharmacy->fresh()->business_type->value);
    }

    public function test_negative_figures_are_rejected(): void
    {
        $this->as($this->pharmacy->user)->patchJson('/api/v1/me/business', ['monthly_expenses' => -1, 'staff_count' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('monthly_expenses', 'error.details')
            ->assertJsonValidationErrorFor('staff_count', 'error.details');
    }

    public function test_staff_have_no_business_profile_and_get_403(): void
    {
        $this->as($this->staff($this->institution, Role::LoanOfficer))->getJson('/api/v1/me/business')->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/business')->assertUnauthorized();
    }
}
