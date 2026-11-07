<?php

namespace App\Exceptions\Api;

class TenantContextMissing extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This endpoint must be called in the context of an institution. Send the X-Tenant header or authenticate as a tenant user.', array $details = [])
    {
        parent::__construct($message, 'TENANT_REQUIRED', 400, $details);
    }
}
