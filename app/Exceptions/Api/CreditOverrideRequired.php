<?php

namespace App\Exceptions\Api;

class CreditOverrideRequired extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The credit assessment recommends declining this loan. Provide an override_reason to approve it.', array $details = [])
    {
        parent::__construct($message, 'CREDIT_OVERRIDE_REQUIRED', 422, $details);
    }
}
