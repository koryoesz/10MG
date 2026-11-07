<?php

namespace App\Repositories\Contracts;

use App\Models\Institution;
use App\Models\InstitutionBranding;

interface BrandingRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createFor(Institution $institution, array $attributes): InstitutionBranding;

    /**
     * The current tenant's branding.
     */
    public function current(): InstitutionBranding;

    /**
     * The current tenant's branding, locked for update (call inside a transaction).
     */
    public function currentForUpdate(): InstitutionBranding;

    public function save(InstitutionBranding $branding): InstitutionBranding;
}
