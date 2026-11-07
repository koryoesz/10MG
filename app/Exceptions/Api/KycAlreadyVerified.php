<?php

namespace App\Exceptions\Api;

class KycAlreadyVerified extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = "The business owner's identity has already been verified.", array $details = [])
    {
        parent::__construct($message, 'KYC_ALREADY_VERIFIED', 409, $details);
    }
}
