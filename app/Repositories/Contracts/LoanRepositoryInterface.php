<?php

namespace App\Repositories\Contracts;

use App\Models\CreditAssessment;
use App\Models\Customer;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

interface LoanRepositoryInterface
{
    /**
     * @param  array{status?: string, customer_id?: string}  $filters
     * @param  Customer|null  $owner  Restrict to one borrower's loans.
     * @return LengthAwarePaginator<int, Loan>
     */
    public function paginate(array $filters, ?Customer $owner = null, int $perPage = 25): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Loan;

    public function save(Loan $loan): Loan;

    /**
     * Re-read the loan holding a row lock (call inside a transaction).
     */
    public function lockForUpdate(Loan $loan): Loan;

    public function countOpenFor(Customer $customer): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordAssessment(Loan $loan, array $attributes): CreditAssessment;

    /**
     * @param  list<string>  $relations
     */
    public function load(Loan $loan, array $relations): Loan;

    /**
     * Disbursed loans in the current tenant with an unpaid installment due before the date.
     *
     * @return LazyCollection<int, Loan>
     */
    public function withInstallmentsDueBefore(CarbonImmutable $date): LazyCollection;
}
