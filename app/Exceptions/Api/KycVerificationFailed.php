<?php

namespace App\Exceptions\Api;

class KycVerificationFailed extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The BVN or NIN could not be verified with the details provided.', array $details = [])
    {
        parent::__construct($message, 'KYC_VERIFICATION_FAILED', 422, $details);
    }
}
