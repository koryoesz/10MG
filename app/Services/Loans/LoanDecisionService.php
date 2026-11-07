<?php

namespace App\Services\Loans;

use App\Enums\CreditRecommendation;
use App\Enums\LoanStatus;
use App\Exceptions\Api\CreditOverrideRequired;
use App\Models\Loan;
use App\Models\User;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Approve, reject or cancel an application. Each runs under the loan's row lock.
 */
class LoanDecisionService
{
    public function __construct(
        private LoanRepositoryInterface $loans,
        private AuditLogger $audit,
    ) {}

    /**
     * `pending` → `approved`. Approving a `decline` recommendation needs an override reason.
     */
    public function approve(Loan $loan, User $reviewer, ?string $note = null, ?string $overrideReason = null): Loan
    {
        return DB::transaction(function () use ($loan, $reviewer, $note, $overrideReason): Loan {
            $loan = $this->loans->lockForUpdate($loan);
            $loan->transitionTo(LoanStatus::Approved);

            $recommendation = $loan->creditAssessment?->recommendation;

            if ($recommendation === CreditRecommendation::Decline && blank($overrideReason)) {
                throw new CreditOverrideRequired(details: ['credit_score' => $loan->creditAssessment->score]);
            }

            $loan->forceFill([
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'decision_reason' => $overrideReason ?? $note,
            ]);
            $this->loans->save($loan);

            $this->audit->record('loan.approved', $loan,
                ['status' => LoanStatus::Pending->value],
                array_filter([
                    'status' => LoanStatus::Approved->value,
                    'note' => $note,
                    'credit_override_reason' => $overrideReason,
                    'credit_recommendation' => $recommendation?->value,
                ]),
                $reviewer,
            );

            return $this->loans->load($loan, ['creditAssessment']);
        });
    }

    /**
     * `pending` → `rejected`.
     */
    public function reject(Loan $loan, User $reviewer, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $reviewer, $reason): Loan {
            $loan = $this->loans->lockForUpdate($loan);
            $previous = $loan->status;
            $loan->transitionTo(LoanStatus::Rejected);

            $loan->forceFill([
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'decision_reason' => $reason,
                'closed_at' => now(),
            ]);
            $this->loans->save($loan);

            $this->audit->record('loan.rejected', $loan,
                ['status' => $previous->value],
                ['status' => LoanStatus::Rejected->value, 'reason' => $reason],
                $reviewer,
            );

            return $loan;
        });
    }

    /**
     * `pending` or `approved` → `cancelled` (before any money moves).
     */
    public function cancel(Loan $loan, User $actor, ?string $reason = null): Loan
    {
        return DB::transaction(function () use ($loan, $actor, $reason): Loan {
            $loan = $this->loans->lockForUpdate($loan);
            $previous = $loan->status;
            $loan->transitionTo(LoanStatus::Cancelled);
            $loan->forceFill(['closed_at' => now(), 'decision_reason' => $reason]);
            $this->loans->save($loan);

            $this->audit->record('loan.cancelled', $loan,
                ['status' => $previous->value],
                array_filter(['status' => LoanStatus::Cancelled->value, 'reason' => $reason]),
                $actor,
            );

            return $loan;
        });
    }
}
