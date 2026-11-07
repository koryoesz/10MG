<?php

namespace App\Services;

use App\Models\InstitutionBranding;
use App\Repositories\Contracts\BrandingRepositoryInterface;
use Illuminate\Support\Facades\DB;

class BrandingService
{
    public function __construct(
        private BrandingRepositoryInterface $brandings,
        private AuditLogger $audit,
    ) {}

    public function current(): InstitutionBranding
    {
        return $this->brandings->current();
    }

    /**
     * Partially update the current tenant's branding, auditing only what changed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): InstitutionBranding
    {
        return DB::transaction(function () use ($attributes): InstitutionBranding {
            $branding = $this->brandings->currentForUpdate()->fill($attributes);
            $this->audit->recordChanges('branding.updated', $branding);

            return $this->brandings->save($branding);
        });
    }
}
