<?php

namespace App\Repositories\Eloquent;

use App\Models\Institution;
use App\Models\InstitutionKycSetting;
use App\Repositories\Contracts\KycSettingsRepositoryInterface;

class KycSettingsRepository implements KycSettingsRepositoryInterface
{
    public function createFor(Institution $institution): InstitutionKycSetting
    {
        $settings = InstitutionKycSetting::withDefaults();
        $settings->institution_id = $institution->getKey();
        $settings->save();

        return $settings;
    }

    public function current(): InstitutionKycSetting
    {
        return InstitutionKycSetting::query()->first() ?? InstitutionKycSetting::withDefaults();
    }

    public function currentForUpdate(): InstitutionKycSetting
    {
        return InstitutionKycSetting::query()->lockForUpdate()->first() ?? InstitutionKycSetting::withDefaults();
    }

    public function save(InstitutionKycSetting $settings): InstitutionKycSetting
    {
        $settings->save();

        return $settings;
    }
}
