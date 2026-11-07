<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanProduct;
use App\Models\LoanTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->institution = Institution::factory()->create();
        $this->product = LoanProduct::factory()->forInstitution($this->institution)->withStandardFees()->create();
    }

    public function test_application_snapshots_product_terms_and_runs_a_credit_assessment(): void
    {
        $customer = $this->customer($this->institution);

        $response = $this->as($customer->user)->idempotentPost('/api/v1/loans', [
            'loan_product_id' => $this->product->id,
            'amount' => 30_000_000,
            'tenor' => 6,
            'purpose' => 'medical_equipment',
            'purpose_note' => 'Second-hand ultrasound scanner',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.principal_amount', 30_000_000)
            ->assertJsonPath('data.interest_rate_bps', 2400)
            ->assertJsonPath('data.upfront_fees', 300_000)
            ->assertJsonPath('data.capitalized_fees', 50_000)
            ->assertJsonPath('data.net_disbursement_amount', 29_700_000)
            ->assertJsonPath('data.credit_assessment.recommendation', 'approve')
            ->assertJsonPath('data.credit_assessment.provider', 'mock-healthcare-scorer')
            ->assertJsonPath('data.purpose', 'medical_equipment');

        $this->assertMatchesRegularExpression('/^LN-20261105-[A-Z0-9]{8}$/', $response->json('data.reference'));
        $this->assertDatabaseHas('credit_assessments', ['loan_id' => $response->json('data.id')]);
    }

    public function test_full_lifecycle_from_application_to_repaid(): void
    {
        $customer = $this->customer($this->institution);
        $loan = $this->loanFor($customer, $this->product, 'pending', 12_000_000, 3);

        $this->as($this->staff($this->institution, Role::LoanOfficer))
            ->idempotentPost("/api/v1/loans/{$loan->id}/approve", ['note' => 'Payslips verified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $finance = $this->staff($this->institution, Role::FinanceOfficer);
        $disbursed = $this->as($finance)->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertOk()
            ->assertJsonPath('data.status', 'disbursed')
            ->assertJsonPath('data.first_due_date', '2026-12-05')
            ->assertJsonPath('data.maturity_date', '2027-02-05')
            ->assertJsonCount(3, 'data.installments');

        $totalRepayable = $disbursed->json('data.total_repayable');
        $this->assertSame($totalRepayable, $disbursed->json('data.outstanding_balance'));
        $this->assertStringStartsWith('MOCK-', $disbursed->json('data.disbursement_reference'));

        $this->as($finance)->idempotentPost("/api/v1/loans/{$loan->id}/repayments", [
            'amount' => $totalRepayable,
            'channel' => 'bank_transfer',
            'external_reference' => 'NIP-0001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.loan.status', 'repaid')
            ->assertJsonPath('data.loan.outstanding_balance', 0)
            ->assertJsonPath('data.transaction.balance_after', 0);

        $this->assertSame(3, $this->unscoped(LoanInstallment::class)->where('loan_id', $loan->id)->where('status', 'paid')->count());
        $this->assertSame(
            ['disbursement', 'fee', 'repayment'],
            $this->unscoped(LoanTransaction::class)->where('loan_id', $loan->id)->orderBy('created_at')->orderBy('id')->pluck('type')->map->value->all(),
        );
        $this->assertNotNull($this->unscoped(Loan::class)->find($loan->id)->closed_at);
    }

    public function test_rejection_requires_a_reason_and_closes_the_application(): void
    {
        $loan = $this->loanFor($this->customer($this->institution), $this->product);
        $officer = $this->staff($this->institution, Role::LoanOfficer);

        $this->as($officer)->idempotentPost("/api/v1/loans/{$loan->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('reason', 'error.details');

        $this->as($officer)->idempotentPost("/api/v1/loans/{$loan->id}/reject", ['reason' => 'Income could not be verified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_reason', 'Income could not be verified');
    }

    public function test_customer_can_cancel_own_loan_before_disbursement(): void
    {
        $customer = $this->customer($this->institution);
        $loan = $this->loanFor($customer, $this->product, 'approved');

        $this->as($customer->user)->idempotentPost("/api/v1/loans/{$loan->id}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_the_approver_cannot_also_disburse_and_gets_403(): void
    {
        $loan = $this->loanFor($this->customer($this->institution), $this->product);
        $admin = $this->staff($this->institution, Role::InstitutionAdmin);

        $this->as($admin)->idempotentPost("/api/v1/loans/{$loan->id}/approve", ['override_reason' => 'Approved for test scenario'])->assertOk();

        $this->as($admin)->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SEGREGATION_OF_DUTIES');
        $this->assertSame('approved', $this->unscoped(Loan::class)->find($loan->id)->status->value);

        $this->as($this->staff($this->institution, Role::InstitutionAdmin))
            ->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertOk();
    }

    public function test_four_eyes_rule_can_be_disabled_for_single_operator_lenders(): void
    {
        config(['lending.enforce_four_eyes' => false]);
        $loan = $this->loanFor($this->customer($this->institution), $this->product);
        $admin = $this->staff($this->institution, Role::InstitutionAdmin);

        $this->as($admin)->idempotentPost("/api/v1/loans/{$loan->id}/approve", ['override_reason' => 'Approved for test scenario'])->assertOk();
        $this->as($admin)->idempotentPost("/api/v1/loans/{$loan->id}/disburse")->assertOk();
    }

    public function test_absurdly_large_amounts_are_rejected_by_validation(): void
    {
        $this->as($this->customer($this->institution)->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => PHP_INT_MAX, 'tenor' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('amount', 'error.details');
    }

    public function test_disbursing_a_pending_loan_returns_409(): void
    {
        $loan = $this->loanFor($this->customer($this->institution), $this->product);

        $this->as($this->staff($this->institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_LOAN_STATE')
            ->assertJsonPath('error.details.current_status', 'pending');
    }

    public function test_disbursing_twice_returns_409_and_pays_out_once(): void
    {
        $loan = $this->loanFor($this->customer($this->institution), $this->product, 'disbursed');

        $this->as($this->staff($this->institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertConflict();

        $this->assertSame(1, $this->unscoped(LoanTransaction::class)->where('loan_id', $loan->id)->where('type', 'disbursement')->count());
    }

    public function test_cancelling_a_disbursed_loan_returns_409(): void
    {
        $customer = $this->customer($this->institution);
        $loan = $this->loanFor($customer, $this->product, 'disbursed');

        $this->as($customer->user)->idempotentPost("/api/v1/loans/{$loan->id}/cancel")->assertConflict();
    }

    public function test_approving_a_declined_application_requires_an_override_reason(): void
    {
        $customer = Customer::factory()->forInstitution($this->institution)->struggling()->create();
        $loan = $this->as($customer->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => 50_000_000, 'tenor' => 3])
            ->assertCreated()
            ->assertJsonPath('data.credit_assessment.recommendation', 'decline');

        $officer = $this->staff($this->institution, Role::LoanOfficer);

        $this->as($officer)->idempotentPost("/api/v1/loans/{$loan->json('data.id')}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CREDIT_OVERRIDE_REQUIRED');

        $this->as($officer)->idempotentPost("/api/v1/loans/{$loan->json('data.id')}/approve", [
            'override_reason' => 'Guaranteed by employer letter on file',
        ])->assertOk()->assertJsonPath('data.decision_reason', 'Guaranteed by employer letter on file');
    }

    public function test_amount_and_tenor_outside_product_limits_return_422(): void
    {
        $customer = $this->customer($this->institution);

        $this->as($customer->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => 500, 'tenor' => 99])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOAN_TERMS_VIOLATION')
            ->assertJsonPath('error.details.amount', 'Amount must be between 1000000 and 100000000 (minor units).')
            ->assertJsonPath('error.details.tenor', 'Tenor must be between 1 and 12 installments.');

        $this->assertSame(0, $this->unscoped(Loan::class)->count());
    }

    public function test_inactive_product_cannot_be_applied_for(): void
    {
        $product = LoanProduct::factory()->forInstitution($this->institution)->inactive()->create();

        $this->as($this->customer($this->institution)->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOAN_TERMS_VIOLATION');
    }

    public function test_business_with_an_expired_licence_is_recommended_for_decline(): void
    {
        $customer = Customer::factory()->forInstitution($this->institution)->withExpiredLicence()->create();

        $this->as($customer->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertCreated()
            ->assertJsonPath('data.credit_assessment.recommendation', 'decline')
            ->assertJsonPath('data.credit_assessment.factors.0', ['factor' => 'operating_licence', 'impact' => -200, 'detail' => 'expired']);
    }

    public function test_product_restricted_to_pharmacies_rejects_a_clinic_with_422(): void
    {
        $product = LoanProduct::factory()->forInstitution($this->institution)->create([
            'eligible_business_types' => [BusinessType::Pharmacy->value],
        ]);
        $clinic = Customer::factory()->forInstitution($this->institution)->ofType(BusinessType::Clinic)->create();

        $this->as($clinic->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOAN_TERMS_VIOLATION')
            ->assertJsonPath('error.details.business_type', 'clinic');

        $pharmacy = Customer::factory()->forInstitution($this->institution)->create();
        $this->as($pharmacy->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertCreated();
    }

    public function test_customer_is_limited_to_the_configured_number_of_open_loans(): void
    {
        config(['lending.max_open_loans_per_customer' => 1]);
        $customer = $this->customer($this->institution);
        $this->loanFor($customer, $this->product);

        $this->as($customer->user)
            ->idempotentPost('/api/v1/loans', ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => 5_000_000, 'tenor' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'OPEN_LOAN_LIMIT_REACHED');
    }

    public function test_application_validation_reports_all_missing_fields(): void
    {
        $this->as($this->customer($this->institution)->user)
            ->idempotentPost('/api/v1/loans', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('loan_product_id', 'error.details')
            ->assertJsonValidationErrorFor('amount', 'error.details')
            ->assertJsonValidationErrorFor('tenor', 'error.details')
            ->assertJsonValidationErrorFor('purpose', 'error.details');
    }

    public function test_customer_only_sees_their_own_loans(): void
    {
        $mine = $this->customer($this->institution);
        $theirs = $this->customer($this->institution);
        $myLoan = $this->loanFor($mine, $this->product);
        $theirLoan = $this->loanFor($theirs, $this->product);

        $this->as($mine->user)->getJson('/api/v1/loans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myLoan->id);

        $this->as($mine->user)->getJson("/api/v1/loans/{$theirLoan->id}")->assertForbidden();
    }

    public function test_quote_endpoint_prices_without_persisting_anything(): void
    {
        $this->getJson('/api/v1/tenant', ['X-Tenant' => $this->institution->slug])->assertOk();

        $this->postJson("/api/v1/tenant/loan-products/{$this->product->id}/quote", ['amount' => 50_000_000, 'tenor' => 6], ['X-Tenant' => $this->institution->slug])
            ->assertOk()
            ->assertJsonPath('data.total_interest', 3_557_744)
            ->assertJsonPath('data.net_disbursement_amount', 49_500_000)
            ->assertJsonCount(6, 'data.schedule');

        $this->assertSame(0, $this->unscoped(Loan::class)->count());
    }
}
