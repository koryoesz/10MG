<?php

namespace App\Exceptions\Api;

class IdentityProviderUnavailable extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The identity verification provider is unavailable. Please try again shortly.', array $details = [])
    {
        parent::__construct($message, 'IDENTITY_PROVIDER_UNAVAILABLE', 503, $details);
    }
}
