<?php

namespace App\Services\Loans;

use App\Contracts\DisbursementGateway;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Exceptions\Api\DisbursementFailed;
use App\Exceptions\Api\SegregationOfDutiesViolation;
use App\Models\Loan;
use App\Models\User;
use App\Repositories\Contracts\LoanInstallmentRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\LoanTransactionRepositoryInterface;
use App\Services\AuditLogger;
use App\Services\Lending\FeeTerm;
use App\Services\Lending\LoanQuote;
use App\Services\Lending\RepaymentScheduleCalculator;
use App\Services\PlatformFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Releases funds for an approved loan. In one transaction (holding the loan's row lock) it
 * calls the payment rail, generates the repayment schedule from today, posts the ledger
 * entries and activates the loan. Any failure rolls everything back.
 */
class DisbursementService
{
    public function __construct(
        private DisbursementGateway $gateway,
        private RepaymentScheduleCalculator $calculator,
        private LoanRepositoryInterface $loans,
        private LoanInstallmentRepositoryInterface $installments,
        private LoanTransactionRepositoryInterface $ledger,
        private PlatformFeeService $platformFees,
        private AuditLogger $audit,
    ) {}

    public function disburse(Loan $loan, User $actor): Loan
    {
        try {
            return DB::transaction(fn (): Loan => $this->release($loan, $actor));
        } catch (DisbursementFailed $exception) {
            $this->audit->record('loan.disbursement_failed', $loan, newValues: $exception->details, actor: $actor);

            throw $exception;
        }
    }

    private function release(Loan $loan, User $actor): Loan
    {
        $loan = $this->loans->lockForUpdate($loan);
        $loan->transitionTo(LoanStatus::Disbursed);

        if (config('lending.enforce_four_eyes') && $loan->reviewed_by === $actor->id) {
            throw new SegregationOfDutiesViolation(details: ['reviewed_by' => $loan->reviewed_by]);
        }

        $quote = $this->reprice($loan);
        $result = $this->gateway->disburse($loan, $loan->net_disbursement_amount, "DSB-{$loan->id}");

        if (! $result->successful) {
            throw new DisbursementFailed(details: ['reason' => $result->failureReason]);
        }

        $this->createSchedule($loan, $quote);
        $this->postLedgerEntries($loan, $actor, $result->providerReference);
        $platformFee = $this->platformFees->accrue($loan);

        $loan->forceFill([
            'disbursed_by' => $actor->id,
            'disbursed_at' => now(),
            'disbursement_reference' => $result->providerReference,
            'outstanding_balance' => $loan->total_repayable,
            'first_due_date' => $quote->schedule[0]->dueDate,
            'maturity_date' => $quote->schedule[array_key_last($quote->schedule)]->dueDate,
        ]);
        $this->loans->save($loan);

        $this->audit->record('loan.disbursed', $loan,
            ['status' => LoanStatus::Approved->value],
            [
                'status' => LoanStatus::Disbursed->value,
                'net_disbursement_amount' => $loan->net_disbursement_amount,
                'disbursement_reference' => $result->providerReference,
                'installments' => count($quote->schedule),
                'platform_fee' => $platformFee?->amount ?? 0,
            ],
            $actor,
        );

        return $this->loans->load($loan, ['installments']);
    }

    /**
     * Re-price from the snapshotted terms with today as the start date; amounts must match.
     */
    private function reprice(Loan $loan): LoanQuote
    {
        $quote = $this->calculator->quote(
            principal: $loan->principal_amount,
            tenor: $loan->tenor,
            interestRateBps: $loan->interest_rate_bps,
            method: $loan->interest_method,
            frequency: $loan->repayment_frequency,
            fees: array_map(FeeTerm::fromArray(...), $loan->fees_snapshot),
            startDate: CarbonImmutable::today(),
        );

        if ($quote->totalRepayable() !== $loan->total_repayable || $quote->netDisbursement() !== $loan->net_disbursement_amount) {
            throw new LogicException("Re-priced schedule for loan {$loan->id} does not match the approved terms.");
        }

        return $quote;
    }

    private function createSchedule(Loan $loan, LoanQuote $quote): void
    {
        foreach ($quote->schedule as $line) {
            $this->installments->create([
                'institution_id' => $loan->institution_id,
                'loan_id' => $loan->id,
                'sequence' => $line->sequence,
                'due_date' => $line->dueDate,
                'principal_due' => $line->principal,
                'interest_due' => $line->interest,
                'fee_due' => $line->fee,
                'total_due' => $line->total(),
                'status' => InstallmentStatus::Pending,
            ]);
        }
    }

    private function postLedgerEntries(Loan $loan, User $actor, ?string $providerReference): void
    {
        $this->ledger->post([
            'loan_id' => $loan->id,
            'type' => TransactionType::Disbursement,
            'amount' => $loan->net_disbursement_amount,
            'external_reference' => $providerReference,
            'channel' => 'bank_transfer',
            'balance_before' => 0,
            'balance_after' => $loan->total_repayable,
            'performed_by' => $actor->id,
            'meta' => ['principal_amount' => $loan->principal_amount],
        ]);

        if ($loan->upfront_fees > 0) {
            $this->ledger->post([
                'loan_id' => $loan->id,
                'type' => TransactionType::Fee,
                'amount' => $loan->upfront_fees,
                'channel' => 'deducted_at_source',
                'balance_before' => $loan->total_repayable,
                'balance_after' => $loan->total_repayable,
                'performed_by' => $actor->id,
                'meta' => ['fees' => $loan->fees_snapshot],
            ]);
        }
    }
}
