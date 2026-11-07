<?php

namespace App\Exceptions\Api;

class IdempotencyKeyReused extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This Idempotency-Key was already used with a different request payload.', array $details = [])
    {
        parent::__construct($message, 'IDEMPOTENCY_KEY_REUSED', 422, $details);
    }
}
