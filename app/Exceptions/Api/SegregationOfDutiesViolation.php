<?php

namespace App\Exceptions\Api;

class SegregationOfDutiesViolation extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The person who approved a loan cannot also disburse it. Another authorised user must release the funds.', array $details = [])
    {
        parent::__construct($message, 'SEGREGATION_OF_DUTIES', 403, $details);
    }
}
