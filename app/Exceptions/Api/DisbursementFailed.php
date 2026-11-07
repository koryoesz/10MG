<?php

namespace App\Exceptions\Api;

class DisbursementFailed extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The disbursement provider rejected the transfer.', array $details = [])
    {
        parent::__construct($message, 'DISBURSEMENT_FAILED', 502, $details);
    }
}
