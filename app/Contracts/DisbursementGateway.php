<?php

namespace App\Contracts;

use App\Models\Loan;
use App\Services\Disbursement\DisbursementResult;

/**
 * Boundary to the payment rail that moves money to the borrower.
 */
interface DisbursementGateway
{
    public function disburse(Loan $loan, int $amount, string $idempotencyReference): DisbursementResult;
}
