<?php

namespace App\Exceptions\Api;

class KycRequired extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = "Verify the business owner's BVN and NIN before applying for a loan.", array $details = [])
    {
        parent::__construct($message, 'KYC_REQUIRED', 422, $details);
    }
}
