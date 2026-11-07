<?php

namespace App\Services\Loans;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Repositories\Contracts\InstitutionRepositoryInterface;
use App\Repositories\Contracts\LoanInstallmentRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\LoanTransactionRepositoryInterface;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Flags installments that are past due plus the loan's grace period, charging the loan's
 * late fee once per installment (an installment already overdue is skipped).
 */
class OverdueService
{
    public function __construct(
        private InstitutionRepositoryInterface $institutions,
        private LoanRepositoryInterface $loans,
        private LoanInstallmentRepositoryInterface $installments,
        private LoanTransactionRepositoryInterface $ledger,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * Process every institution, each inside its own tenant context.
     *
     * @return int Installments newly marked overdue.
     */
    public function markAllOverdue(CarbonImmutable $asOf): int
    {
        $total = 0;

        foreach ($this->institutions->all() as $institution) {
            $total += $this->tenant->runAs($institution, fn (Institution $institution): int => $this->markOverdueForTenant($asOf));
        }

        return $total;
    }

    public function markOverdueForTenant(CarbonImmutable $asOf): int
    {
        $count = 0;

        foreach ($this->loans->withInstallmentsDueBefore($asOf) as $loan) {
            $count += $this->markOverdue($loan, $asOf);
        }

        return $count;
    }

    public function markOverdue(Loan $loan, CarbonImmutable $asOf): int
    {
        return DB::transaction(function () use ($loan, $asOf): int {
            $loan = $this->loans->lockForUpdate($loan);

            if ($loan->status !== LoanStatus::Disbursed) {
                return 0;
            }

            $due = $this->installments->lockDueBefore($loan, $asOf->subDays($loan->grace_period_days));

            foreach ($due as $installment) {
                $penalty = $loan->late_fee_type?->apply($loan->late_fee_value, $installment->outstanding()) ?? 0;

                $installment->status = InstallmentStatus::Overdue;
                $installment->penalty_due += $penalty;
                $installment->total_due += $penalty;
                $this->installments->save($installment);

                if ($penalty > 0) {
                    $this->chargePenalty($loan, $installment, $penalty);
                }
            }

            return $due->count();
        });
    }

    private function chargePenalty(Loan $loan, LoanInstallment $installment, int $penalty): void
    {
        $balanceBefore = $loan->outstanding_balance;
        $loan->penalty_fees += $penalty;
        $loan->total_repayable += $penalty;
        $loan->outstanding_balance += $penalty;
        $this->loans->save($loan);

        $this->ledger->post([
            'loan_id' => $loan->id,
            'type' => TransactionType::Penalty,
            'amount' => $penalty,
            'channel' => 'system',
            'balance_before' => $balanceBefore,
            'balance_after' => $loan->outstanding_balance,
            'meta' => ['installment_sequence' => $installment->sequence],
        ]);

        $this->audit->record('loan.late_fee_charged', $loan,
            ['outstanding_balance' => $balanceBefore],
            ['outstanding_balance' => $loan->outstanding_balance, 'penalty' => $penalty, 'installment' => $installment->sequence],
        );
    }
}
