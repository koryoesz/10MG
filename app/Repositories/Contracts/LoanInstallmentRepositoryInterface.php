<?php

namespace App\Repositories\Contracts;

use App\Models\Loan;
use App\Models\LoanInstallment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

interface LoanInstallmentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LoanInstallment;

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function forLoan(Loan $loan): Collection;

    /**
     * Unpaid installments, oldest first, row-locked (call inside a transaction).
     *
     * @return Collection<int, LoanInstallment>
     */
    public function lockUnpaid(Loan $loan): Collection;

    /**
     * Pending or part-paid installments due before the cut-off, row-locked.
     *
     * @return Collection<int, LoanInstallment>
     */
    public function lockDueBefore(Loan $loan, CarbonImmutable $cutOff): Collection;

    public function save(LoanInstallment $installment): LoanInstallment;
}
