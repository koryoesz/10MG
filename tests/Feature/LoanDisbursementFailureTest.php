<?php

namespace Tests\Feature;

use App\Contracts\DisbursementGateway;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanProduct;
use App\Models\LoanTransaction;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanDisbursementFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_transfer_returns_502_rolls_back_and_records_the_failure(): void
    {
        $institution = Institution::factory()->create();
        $loan = $this->loanFor($this->customer($institution), LoanProduct::factory()->forInstitution($institution)->create(), 'approved');

        $this->app->instance(DisbursementGateway::class, new class implements DisbursementGateway
        {
            public function disburse(Loan $loan, int $amount, string $idempotencyReference): DisbursementResult
            {
                return DisbursementResult::failure('Beneficiary account is dormant');
            }
        });

        $this->as($this->staff($institution, Role::FinanceOfficer))
            ->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'DISBURSEMENT_FAILED')
            ->assertJsonPath('error.details.reason', 'Beneficiary account is dormant');

        $this->assertSame('approved', $this->unscoped(Loan::class)->find($loan->id)->status->value);
        $this->assertSame(0, $this->unscoped(LoanInstallment::class)->count());
        $this->assertSame(0, $this->unscoped(LoanTransaction::class)->count());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'loan.disbursement_failed', 'auditable_id' => $loan->id]);
    }
}
