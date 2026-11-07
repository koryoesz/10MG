<?php

namespace App\Repositories\Eloquent;

use App\Models\Institution;
use App\Models\InstitutionBranding;
use App\Repositories\Contracts\BrandingRepositoryInterface;

class BrandingRepository implements BrandingRepositoryInterface
{
    public function createFor(Institution $institution, array $attributes): InstitutionBranding
    {
        $branding = new InstitutionBranding($attributes);
        $branding->institution_id = $institution->getKey();
        $branding->save();

        return $branding;
    }

    public function current(): InstitutionBranding
    {
        return InstitutionBranding::query()->firstOrFail();
    }

    public function currentForUpdate(): InstitutionBranding
    {
        return InstitutionBranding::query()->lockForUpdate()->firstOrFail();
    }

    public function save(InstitutionBranding $branding): InstitutionBranding
    {
        $branding->save();

        return $branding;
    }
}
