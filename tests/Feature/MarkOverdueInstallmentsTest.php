<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanProduct;
use App\Models\LoanTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarkOverdueInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-10');
        $this->institution = Institution::factory()->create();
        // 3-day grace period, 5% late fee on the overdue installment's outstanding amount.
        $product = LoanProduct::factory()->forInstitution($this->institution)->create();
        $this->loan = $this->loanFor($this->customer($this->institution), $product, 'disbursed', 12_000_000, 3);
    }

    public function test_installment_within_grace_period_is_not_marked_overdue(): void
    {
        // First installment due 2026-02-10; grace runs to 2026-02-13.
        $this->artisan('loans:mark-overdue', ['--date' => '2026-02-13'])->assertSuccessful();

        $this->assertSame('pending', $this->installment(1)->status->value);
    }

    public function test_overdue_installment_is_flagged_and_charged_a_late_fee_once(): void
    {
        $outstanding = $this->installment(1)->outstanding();
        $expectedPenalty = intdiv($outstanding * 500 + 5000, 10000);

        $this->artisan('loans:mark-overdue', ['--date' => '2026-02-14'])
            ->expectsOutputToContain('Marked 1 installment(s) overdue')
            ->assertSuccessful();
        $this->artisan('loans:mark-overdue', ['--date' => '2026-02-20'])->assertSuccessful();

        $installment = $this->installment(1);
        $loan = $this->unscoped(Loan::class)->find($this->loan->id);

        $this->assertSame('overdue', $installment->status->value);
        $this->assertSame($expectedPenalty, $installment->penalty_due);
        $this->assertSame($expectedPenalty, $loan->penalty_fees);
        $this->assertSame($this->loan->total_repayable + $expectedPenalty, $loan->outstanding_balance);
        $this->assertSame(1, $this->unscoped(LoanTransaction::class)->where('type', 'penalty')->count());
    }

    public function test_repayment_settles_the_penalty_before_anything_else(): void
    {
        $this->artisan('loans:mark-overdue', ['--date' => '2026-02-14']);
        $penalty = $this->installment(1)->penalty_due;

        $this->as($this->staff($this->institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => $penalty, 'channel' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.transaction.allocation.0.penalty', $penalty)
            ->assertJsonPath('data.transaction.allocation.0.interest', 0);

        $this->assertSame('overdue', $this->installment(1)->status->value);
    }

    public function test_paid_installments_are_never_marked_overdue(): void
    {
        $this->as($this->staff($this->institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$this->loan->id}/repayments", ['amount' => $this->installment(1)->total_due, 'channel' => 'cash'])
            ->assertCreated();

        $this->artisan('loans:mark-overdue', ['--date' => '2026-02-20'])->expectsOutputToContain('Marked 0 installment(s)');

        $this->assertSame('paid', $this->installment(1)->status->value);
    }

    private function installment(int $sequence): LoanInstallment
    {
        return $this->unscoped(LoanInstallment::class)->where('loan_id', $this->loan->id)->where('sequence', $sequence)->firstOrFail();
    }
}
