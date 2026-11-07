<?php

namespace App\Services\Loans;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Exceptions\Api\DuplicatePaymentReference;
use App\Exceptions\Api\InvalidLoanState;
use App\Exceptions\Api\Overpayment;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\User;
use App\Repositories\Contracts\LoanInstallmentRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\LoanTransactionRepositoryInterface;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies a payment to a disbursed loan.
 *
 * Allocation is waterfall: oldest installment first; within an installment
 * penalty → fees → interest → principal. The loan closes when its balance reaches zero.
 */
class RepaymentService
{
    /** @var list<string> */
    private const COMPONENTS = ['penalty', 'fee', 'interest', 'principal'];

    public function __construct(
        private LoanRepositoryInterface $loans,
        private LoanInstallmentRepositoryInterface $installments,
        private LoanTransactionRepositoryInterface $ledger,
        private AuditLogger $audit,
    ) {}

    public function record(Loan $loan, User $actor, int $amount, string $channel, ?string $externalReference = null): LoanTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Repayment amount must be positive.');
        }

        try {
            return DB::transaction(fn (): LoanTransaction => $this->apply($loan, $actor, $amount, $channel, $externalReference));
        } catch (UniqueConstraintViolationException) {
            throw new DuplicatePaymentReference(details: ['external_reference' => $externalReference]);
        }
    }

    private function apply(Loan $loan, User $actor, int $amount, string $channel, ?string $externalReference): LoanTransaction
    {
        $loan = $this->loans->lockForUpdate($loan);
        $this->ensureAcceptable($loan, $amount, $externalReference);

        $allocation = $this->allocate($loan, $amount);

        $balanceBefore = $loan->outstanding_balance;
        $loan->amount_paid += $amount;
        $loan->outstanding_balance -= $amount;

        if ($loan->outstanding_balance === 0) {
            $loan->transitionTo(LoanStatus::Repaid);
            $loan->closed_at = now();
        }

        $this->loans->save($loan);

        $transaction = $this->ledger->post([
            'loan_id' => $loan->id,
            'type' => TransactionType::Repayment,
            'amount' => $amount,
            'external_reference' => $externalReference,
            'channel' => $channel,
            'balance_before' => $balanceBefore,
            'balance_after' => $loan->outstanding_balance,
            'allocation' => $allocation,
            'performed_by' => $actor->id,
        ]);

        $this->audit->record('loan.repayment_recorded', $loan,
            ['outstanding_balance' => $balanceBefore],
            array_filter([
                'outstanding_balance' => $loan->outstanding_balance,
                'amount' => $amount,
                'transaction_reference' => $transaction->reference,
                'status' => $loan->status === LoanStatus::Repaid ? LoanStatus::Repaid->value : null,
            ], fn (mixed $value): bool => $value !== null),
            $actor,
        );

        return $transaction->setRelation('loan', $loan);
    }

    private function ensureAcceptable(Loan $loan, int $amount, ?string $externalReference): void
    {
        if ($loan->status !== LoanStatus::Disbursed) {
            throw new InvalidLoanState('Repayments can only be made on disbursed loans.', ['current_status' => $loan->status->value]);
        }

        if ($amount > $loan->outstanding_balance) {
            throw new Overpayment(details: ['outstanding_balance' => $loan->outstanding_balance, 'amount' => $amount]);
        }

        if ($externalReference !== null && $this->ledger->externalReferenceExists($externalReference)) {
            throw new DuplicatePaymentReference(details: ['external_reference' => $externalReference]);
        }
    }

    /**
     * @return list<array{sequence: int, penalty: int, fee: int, interest: int, principal: int}>
     */
    private function allocate(Loan $loan, int $amount): array
    {
        $remaining = $amount;
        $allocation = [];

        foreach ($this->installments->lockUnpaid($loan) as $installment) {
            if ($remaining === 0) {
                break;
            }

            $applied = ['sequence' => $installment->sequence];

            foreach (self::COMPONENTS as $component) {
                $paid = min($installment->{"{$component}_due"} - $installment->{"{$component}_paid"}, $remaining);
                $installment->{"{$component}_paid"} += $paid;
                $remaining -= $paid;
                $applied[$component] = $paid;
            }

            $installment->amount_paid = $installment->penalty_paid + $installment->fee_paid
                + $installment->interest_paid + $installment->principal_paid;

            if ($installment->outstanding() === 0) {
                $installment->status = InstallmentStatus::Paid;
                $installment->paid_at = now();
            } elseif ($installment->status === InstallmentStatus::Pending) {
                $installment->status = InstallmentStatus::PartiallyPaid;
            }

            $this->installments->save($installment);
            $allocation[] = $applied;
        }

        return $allocation;
    }
}
