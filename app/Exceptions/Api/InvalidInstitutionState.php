<?php

namespace App\Exceptions\Api;

class InvalidInstitutionState extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'The institution is not in a state that allows this operation.', array $details = [])
    {
        parent::__construct($message, 'INVALID_INSTITUTION_STATE', 409, $details);
    }
}
