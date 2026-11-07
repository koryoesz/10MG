<?php

namespace App\Exceptions\Api;

class OpenLoanLimitReached extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The customer has reached the maximum number of open loans.', array $details = [])
    {
        parent::__construct($message, 'OPEN_LOAN_LIMIT_REACHED', 422, $details);
    }
}
