<?php

namespace App\Exceptions\Api;

class IdempotencyRequestInProgress extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'A request with this Idempotency-Key is still being processed. Retry shortly.', array $details = [])
    {
        parent::__construct($message, 'IDEMPOTENCY_REQUEST_IN_PROGRESS', 409, $details);
    }
}
