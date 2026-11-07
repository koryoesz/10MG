<?php

namespace App\Services\Loans;

use App\Enums\Permission;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\LoanInstallmentRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\LoanTransactionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of loans: staff see every loan in their institution, borrowers only their own.
 */
class LoanService
{
    public function __construct(
        private LoanRepositoryInterface $loans,
        private LoanInstallmentRepositoryInterface $installments,
        private LoanTransactionRepositoryInterface $ledger,
        private CustomerRepositoryInterface $customers,
    ) {}

    /**
     * @param  array{status?: string, customer_id?: string}  $filters
     * @return LengthAwarePaginator<int, Loan>
     */
    public function listFor(User $viewer, array $filters): LengthAwarePaginator
    {
        if ($viewer->hasPermission(Permission::LoansView)) {
            return $this->loans->paginate($filters);
        }

        unset($filters['customer_id']);

        return $this->loans->paginate($filters, $this->customers->findForUser($viewer));
    }

    public function detail(Loan $loan): Loan
    {
        return $this->loans->load($loan, ['creditAssessment', 'customer', 'product', 'installments']);
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function schedule(Loan $loan): Collection
    {
        return $this->installments->forLoan($loan);
    }

    /**
     * @return Collection<int, LoanTransaction>
     */
    public function transactions(Loan $loan): Collection
    {
        return $this->ledger->forLoan($loan);
    }
}
