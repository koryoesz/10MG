<?php

namespace App\Exceptions\Api;

class AccountDisabled extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This account has been disabled.', array $details = [])
    {
        parent::__construct($message, 'ACCOUNT_DISABLED', 403, $details);
    }
}
