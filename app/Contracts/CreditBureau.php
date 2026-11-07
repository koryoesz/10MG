<?php

namespace App\Contracts;

use App\Services\Credit\CreditApplicant;
use App\Services\Credit\CreditDecision;

/**
 * Boundary to a credit-scoring provider (credit bureau, internal scorecard, ML model, ...).
 */
interface CreditBureau
{
    public function assess(CreditApplicant $applicant): CreditDecision;
}
