<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Loan;
use App\Models\User;

/**
 * Tenant isolation is already guaranteed by the global scope (a foreign loan never resolves),
 * so these rules only decide between staff access and customer self-service.
 */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::LoansView) || $user->hasPermission(Permission::LoansViewOwn);
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->hasPermission(Permission::LoansView)
            || ($user->hasPermission(Permission::LoansViewOwn) && $this->owns($user, $loan));
    }

    public function cancel(User $user, Loan $loan): bool
    {
        return $user->hasPermission(Permission::LoansReview)
            || ($user->hasPermission(Permission::LoansApply) && $this->owns($user, $loan));
    }

    public function repay(User $user, Loan $loan): bool
    {
        return $user->hasPermission(Permission::RepaymentsRecord)
            || ($user->hasPermission(Permission::RepaymentsMakeOwn) && $this->owns($user, $loan));
    }

    private function owns(User $user, Loan $loan): bool
    {
        return $user->customer !== null && $user->customer->id === $loan->customer_id;
    }
}
