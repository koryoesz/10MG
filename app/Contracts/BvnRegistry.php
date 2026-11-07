<?php

namespace App\Contracts;

use App\Services\Identity\IdentityQuery;
use App\Services\Identity\ProviderMatch;

/**
 * Boundary to the BVN register held by NIBSS (Nigeria Inter-Bank Settlement System).
 */
interface BvnRegistry
{
    /**
     * Ask the register whether the submitted details match the record held for the BVN.
     */
    public function matchBvn(IdentityQuery $query): ProviderMatch;
}
