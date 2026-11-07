<?php

namespace App\Exceptions\Api;

class InvalidLoanState extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The loan is not in a state that allows this operation.', array $details = [])
    {
        parent::__construct($message, 'INVALID_LOAN_STATE', 409, $details);
    }
}
