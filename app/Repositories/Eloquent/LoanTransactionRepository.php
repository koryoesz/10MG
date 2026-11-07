<?php

namespace App\Repositories\Eloquent;

use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Repositories\Contracts\LoanTransactionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class LoanTransactionRepository implements LoanTransactionRepositoryInterface
{
    public function post(array $attributes): LoanTransaction
    {
        $transaction = new LoanTransaction;
        $transaction->forceFill(['reference' => 'TXN-'.Str::ulid()] + $attributes)->save();

        return $transaction;
    }

    public function forLoan(Loan $loan): Collection
    {
        return $loan->transactions()->get();
    }

    public function externalReferenceExists(string $externalReference): bool
    {
        return LoanTransaction::query()->where('external_reference', $externalReference)->exists();
    }
}
