<?php

namespace App\Repositories\Contracts;

use App\Models\Loan;
use App\Models\LoanTransaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * The append-only ledger: entries can be posted and read, never changed.
 */
interface LoanTransactionRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function post(array $attributes): LoanTransaction;

    /**
     * @return Collection<int, LoanTransaction>
     */
    public function forLoan(Loan $loan): Collection;

    public function externalReferenceExists(string $externalReference): bool;
}
