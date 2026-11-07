<?php

namespace App\Exceptions\Api;

class IdempotencyKeyMissing extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This endpoint requires an Idempotency-Key header.', array $details = [])
    {
        parent::__construct($message, 'IDEMPOTENCY_KEY_REQUIRED', 400, $details);
    }
}
