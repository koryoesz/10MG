<?php

namespace App\Exceptions\Api;

class KycNotEnabled extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This lender has turned off identity checks; there is nothing to verify.', array $details = [])
    {
        parent::__construct($message, 'KYC_NOT_ENABLED', 409, $details);
    }
}
