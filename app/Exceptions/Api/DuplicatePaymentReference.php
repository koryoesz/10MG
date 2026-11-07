<?php

namespace App\Exceptions\Api;

class DuplicatePaymentReference extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'A payment with this external reference has already been recorded.', array $details = [])
    {
        parent::__construct($message, 'DUPLICATE_PAYMENT_REFERENCE', 409, $details);
    }
}
