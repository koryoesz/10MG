<?php

namespace App\Repositories\Eloquent;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\CreditAssessment;
use App\Models\Customer;
use App\Models\Loan;
use App\Repositories\Contracts\LoanRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

class LoanRepository implements LoanRepositoryInterface
{
    public function paginate(array $filters, ?Customer $owner = null, int $perPage = 25): LengthAwarePaginator
    {
        return Loan::query()
            ->with(['creditAssessment', 'customer'])
            ->when($owner, fn (Builder $query, Customer $customer) => $query->where('customer_id', $customer->getKey()))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, string $id) => $query->where('customer_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    public function create(array $attributes): Loan
    {
        $loan = new Loan;
        $loan->forceFill($attributes)->save();

        return $loan;
    }

    public function save(Loan $loan): Loan
    {
        $loan->save();

        return $loan;
    }

    public function lockForUpdate(Loan $loan): Loan
    {
        return Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
    }

    public function countOpenFor(Customer $customer): int
    {
        return $customer->loans()->whereIn('status', LoanStatus::open())->count();
    }

    public function recordAssessment(Loan $loan, array $attributes): CreditAssessment
    {
        $assessment = new CreditAssessment;
        $assessment->forceFill(['loan_id' => $loan->getKey()] + $attributes)->save();

        return $assessment;
    }

    public function load(Loan $loan, array $relations): Loan
    {
        return $loan->load($relations);
    }

    public function withInstallmentsDueBefore(CarbonImmutable $date): LazyCollection
    {
        return Loan::query()
            ->where('status', LoanStatus::Disbursed)
            ->whereHas('installments', fn (Builder $query) => $query
                ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid])
                ->whereDate('due_date', '<', $date))
            ->lazyById();
    }
}
