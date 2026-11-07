<?php

namespace App\Repositories\Contracts;

use App\Models\Institution;
use App\Models\InstitutionKycSetting;

interface KycSettingsRepositoryInterface
{
    public function createFor(Institution $institution): InstitutionKycSetting;

    /**
     * The current tenant's settings, or unsaved platform defaults if it has none yet.
     */
    public function current(): InstitutionKycSetting;

    /**
     * The current tenant's settings locked for update (call inside a transaction), or unsaved defaults.
     */
    public function currentForUpdate(): InstitutionKycSetting;

    public function save(InstitutionKycSetting $settings): InstitutionKycSetting;
}
