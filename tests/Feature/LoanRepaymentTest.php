<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanRepaymentTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $finance;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $product = LoanProduct::factory()->forInstitution($this->institution)->withStandardFees()->create();
        $this->loan = $this->loanFor($this->customer($this->institution), $product, 'disbursed', 12_000_000, 3);
        $this->finance = $this->staff($this->institution, Role::FinanceOfficer);
    }

    public function test_partial_payment_is_allocated_fees_then_interest_then_principal(): void
    {
        $first = $this->installment(1);

        $response = $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", [
            'amount' => $first->fee_due + $first->interest_due + 100,
            'channel' => 'cash',
        ]);

        $response->assertCreated()->assertJsonPath('data.transaction.allocation.0', [
            'sequence' => 1,
            'penalty' => 0,
            'fee' => $first->fee_due,
            'interest' => $first->interest_due,
            'principal' => 100,
        ]);

        $first->refresh();
        $this->assertSame('partially_paid', $first->status->value);
        $this->assertSame($first->principal_due - 100, $first->outstanding());
    }

    public function test_payment_spanning_installments_settles_the_oldest_first(): void
    {
        $first = $this->installment(1);

        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", [
            'amount' => $first->total_due + 1_000,
            'channel' => 'bank_transfer',
        ])->assertCreated();

        $this->assertSame('paid', $this->installment(1)->status->value);
        $this->assertSame(1_000, $this->installment(2)->amount_paid);
    }

    public function test_balances_stay_consistent_between_loan_ledger_and_schedule(): void
    {
        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => 1_234_567, 'channel' => 'card'])->assertCreated();

        $loan = $this->unscoped(Loan::class)->find($this->loan->id);
        $scheduleOutstanding = $this->unscoped(LoanInstallment::class)->where('loan_id', $loan->id)->get()->sum(fn ($i) => $i->outstanding());

        $this->assertSame(1_234_567, $loan->amount_paid);
        $this->assertSame($loan->total_repayable - 1_234_567, $loan->outstanding_balance);
        $this->assertSame($loan->outstanding_balance, $scheduleOutstanding);
    }

    public function test_overpayment_returns_422_and_changes_nothing(): void
    {
        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", [
            'amount' => $this->loan->outstanding_balance + 1,
            'channel' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'OVERPAYMENT')
            ->assertJsonPath('error.details.outstanding_balance', $this->loan->outstanding_balance);

        $this->assertSame(0, $this->unscoped(Loan::class)->find($this->loan->id)->amount_paid);
    }

    public function test_the_same_external_reference_cannot_be_applied_twice_and_returns_409(): void
    {
        $payload = ['amount' => 100_000, 'channel' => 'bank_transfer', 'external_reference' => 'PAYSTACK-T123'];

        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", $payload)->assertCreated();
        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'DUPLICATE_PAYMENT_REFERENCE');

        $this->assertSame(100_000, $this->unscoped(Loan::class)->find($this->loan->id)->amount_paid);
    }

    public function test_repayment_on_an_undisbursed_loan_returns_409(): void
    {
        $product = LoanProduct::factory()->forInstitution($this->institution)->create();
        $pending = $this->loanFor($this->customer($this->institution), $product);

        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$pending->id}/repayments", ['amount' => 100, 'channel' => 'cash'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_LOAN_STATE');
    }

    public function test_customer_can_repay_their_own_loan(): void
    {
        $owner = $this->unscoped(Loan::class)->with(['customer' => fn ($q) => $q->withoutGlobalScopes()])->find($this->loan->id)->customer;

        $this->as(User::query()->findOrFail($owner->user_id))
            ->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => 5_000, 'channel' => 'card'])
            ->assertCreated();
    }

    public function test_borrower_cannot_self_report_a_cash_payment(): void
    {
        $owner = $this->unscoped(Loan::class)->with(['customer' => fn ($q) => $q->withoutGlobalScopes()])->find($this->loan->id)->customer;

        $this->as(User::query()->findOrFail($owner->user_id))
            ->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => 5_000, 'channel' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('channel', 'error.details');

        $this->assertSame(0, $this->unscoped(Loan::class)->find($this->loan->id)->amount_paid);
    }

    public function test_another_customer_cannot_repay_someone_elses_loan(): void
    {
        $this->as($this->customer($this->institution)->user)
            ->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => 5_000, 'channel' => 'card'])
            ->assertForbidden();
    }

    public function test_repayment_on_a_cancelled_loan_returns_409(): void
    {
        $product = LoanProduct::factory()->forInstitution($this->institution)->create();
        $customer = $this->customer($this->institution);
        $loan = $this->loanFor($customer, $product, 'approved');

        $this->as($customer->user)->idempotentPost("/api/v1/loans/{$loan->id}/cancel")->assertOk();

        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$loan->id}/repayments", ['amount' => 1_000, 'channel' => 'cash'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_LOAN_STATE');
        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$loan->id}/disburse")->assertConflict();
    }

    public function test_repayment_validation_rejects_non_positive_amounts_and_unknown_channels(): void
    {
        $this->as($this->finance)->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => 0, 'channel' => 'barter'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('amount', 'error.details')
            ->assertJsonValidationErrorFor('channel', 'error.details');
    }

    private function installment(int $sequence): LoanInstallment
    {
        return $this->unscoped(LoanInstallment::class)->where('loan_id', $this->loan->id)->where('sequence', $sequence)->firstOrFail();
    }
}
