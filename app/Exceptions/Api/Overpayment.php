<?php

namespace App\Exceptions\Api;

class Overpayment extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The payment amount exceeds the outstanding balance.', array $details = [])
    {
        parent::__construct($message, 'OVERPAYMENT', 422, $details);
    }
}
