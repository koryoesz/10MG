<?php

namespace App\Exceptions\Api;

class InvalidCredentials extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The provided credentials are incorrect.', array $details = [])
    {
        parent::__construct($message, 'INVALID_CREDENTIALS', 401, $details);
    }
}
