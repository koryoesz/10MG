<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One refused role per endpoint group proves the route enforces authorization;
 * the full permission matrix is covered by Tests\Unit\Enums\RoleTest.
 */
class RoleBasedAccessTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->product = LoanProduct::factory()->forInstitution($this->institution)->create();
    }

    public function test_loan_officer_cannot_disburse_and_gets_403(): void
    {
        $loan = $this->loanFor($this->customer($this->institution), $this->product, 'approved');

        $this->as($this->staff($this->institution, Role::LoanOfficer))
            ->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertForbidden();

        $this->assertSame('approved', $loan->fresh()->status->value);
    }

    public function test_finance_officer_cannot_approve_and_gets_403(): void
    {
        $loan = $this->loanFor($this->customer($this->institution), $this->product);

        $this->as($this->staff($this->institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$loan->id}/approve")
            ->assertForbidden();
    }

    public function test_customer_cannot_approve_their_own_loan(): void
    {
        $customer = $this->customer($this->institution);
        $loan = $this->loanFor($customer, $this->product);

        $this->as($customer->user)->idempotentPost("/api/v1/loans/{$loan->id}/approve")->assertForbidden();
    }

    public function test_staff_cannot_apply_for_loans(): void
    {
        $this->as($this->staff($this->institution, Role::InstitutionAdmin))
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertForbidden();
    }

    public function test_loan_officer_cannot_change_white_label_configuration(): void
    {
        $this->as($this->staff($this->institution, Role::LoanOfficer));

        $this->patchJson('/api/v1/branding', ['primary_color' => '#000000'])->assertForbidden();
        $this->postJson('/api/v1/loan-products', [])->assertForbidden();
        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_customer_cannot_view_the_back_office_customer_list_or_audit_log(): void
    {
        $this->as($this->customer($this->institution)->user);

        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_platform_operator_cannot_use_tenant_endpoints_and_gets_400(): void
    {
        $this->as(User::factory()->platformAdmin()->create())
            ->getJson('/api/v1/loans')
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }

    public function test_deactivated_staff_lose_their_permissions(): void
    {
        $officer = $this->staff($this->institution, Role::LoanOfficer);
        $officer->forceFill(['is_active' => false])->save();

        $this->as($officer)->getJson('/api/v1/loans')->assertForbidden();
    }

    public function test_admin_provisions_staff_with_a_limited_set_of_roles(): void
    {
        $admin = $this->staff($this->institution, Role::InstitutionAdmin);

        $this->as($admin)->postJson('/api/v1/staff', [
            'name' => 'Ngozi', 'email' => 'ngozi@bank.test', 'password' => 'Secret123', 'role' => 'loan_officer',
        ])->assertCreated()->assertJsonPath('data.role', 'loan_officer');

        $this->as($admin)->postJson('/api/v1/staff', [
            'name' => 'Mallory', 'email' => 'mallory@bank.test', 'password' => 'Secret123', 'role' => 'platform_admin',
        ])->assertUnprocessable()->assertJsonValidationErrorFor('role', 'error.details');
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->staff($this->institution, Role::InstitutionAdmin);

        $this->as($admin)->patchJson("/api/v1/staff/{$admin->id}", ['role' => 'loan_officer'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.role.0', 'You cannot change your own role. Ask another administrator.');

        $this->assertSame(Role::InstitutionAdmin, $admin->fresh()->role);
    }

    public function test_deactivating_staff_revokes_their_tokens_but_admins_cannot_deactivate_themselves(): void
    {
        $admin = $this->staff($this->institution, Role::InstitutionAdmin);
        $officer = $this->staff($this->institution, Role::LoanOfficer);
        $officer->createToken('api');

        $this->as($admin)->patchJson("/api/v1/staff/{$officer->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $officer->tokens()->count());

        $this->as($admin)->patchJson("/api/v1/staff/{$admin->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.is_active.0', 'You cannot deactivate your own account.');
    }
}
