<?php

namespace App\Repositories\Eloquent;

use App\Enums\InstallmentStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Repositories\Contracts\LoanInstallmentRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class LoanInstallmentRepository implements LoanInstallmentRepositoryInterface
{
    public function create(array $attributes): LoanInstallment
    {
        $installment = new LoanInstallment;
        $installment->forceFill($attributes)->save();

        return $installment;
    }

    public function forLoan(Loan $loan): Collection
    {
        return $loan->installments()->get();
    }

    public function lockUnpaid(Loan $loan): Collection
    {
        return LoanInstallment::query()
            ->where('loan_id', $loan->getKey())
            ->where('status', '!=', InstallmentStatus::Paid)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();
    }

    public function lockDueBefore(Loan $loan, CarbonImmutable $cutOff): Collection
    {
        return LoanInstallment::query()
            ->where('loan_id', $loan->getKey())
            ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid])
            ->whereDate('due_date', '<', $cutOff)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();
    }

    public function save(LoanInstallment $installment): LoanInstallment
    {
        $installment->save();

        return $installment;
    }
}
