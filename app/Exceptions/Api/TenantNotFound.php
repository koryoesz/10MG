<?php

namespace App\Exceptions\Api;

class TenantNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'Institution not found.', array $details = [])
    {
        parent::__construct($message, 'TENANT_NOT_FOUND', 404, $details);
    }
}
