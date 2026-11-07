<?php

namespace App\Exceptions\Api;

class TenantMismatch extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The X-Tenant header does not match the institution of the authenticated user.', array $details = [])
    {
        parent::__construct($message, 'TENANT_MISMATCH', 403, $details);
    }
}
