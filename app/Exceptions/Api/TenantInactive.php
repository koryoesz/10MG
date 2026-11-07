<?php

namespace App\Exceptions\Api;

class TenantInactive extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This institution is not active.', array $details = [])
    {
        parent::__construct($message, 'TENANT_INACTIVE', 403, $details);
    }
}
