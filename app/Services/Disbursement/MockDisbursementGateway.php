<?php

namespace App\Services\Disbursement;

use App\Contracts\DisbursementGateway;
use App\Models\Loan;

/**
 * Simulates an instant bank transfer. A real implementation would call the payment rail
 * with `$idempotencyReference` so retries can never pay out twice.
 */
class MockDisbursementGateway implements DisbursementGateway
{
    public function disburse(Loan $loan, int $amount, string $idempotencyReference): DisbursementResult
    {
        if ($amount <= 0) {
            return DisbursementResult::failure('Amount must be positive.');
        }

        return DisbursementResult::success('MOCK-'.strtoupper(substr(hash('sha256', $idempotencyReference), 0, 16)));
    }
}
