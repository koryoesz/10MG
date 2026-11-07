<?php

namespace App\Exceptions\Api;

class LoanTermsViolation extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The requested loan terms are outside the product limits.', array $details = [])
    {
        parent::__construct($message, 'LOAN_TERMS_VIOLATION', 422, $details);
    }
}
