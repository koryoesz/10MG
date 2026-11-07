<?php

namespace App\Contracts;

use App\Services\Identity\IdentityQuery;
use App\Services\Identity\ProviderMatch;

/**
 * Boundary to the National Identity Management Commission (NIMC) NIN register.
 */
interface NinRegistry
{
    /**
     * Ask the register whether the submitted details match the record held for the NIN.
     */
    public function matchNin(IdentityQuery $query): ProviderMatch;
}
