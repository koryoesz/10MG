<?php

namespace App\Http\Resources;

use App\Models\Institution;
use App\Models\InstitutionKycSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public, white-label face of an institution.
 *
 * @mixin Institution
 */
class StorefrontResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'currency' => $this->currency,
            'country_code' => $this->country_code,
            'branding' => new BrandingResource($this->whenLoaded('branding')),
            /** What a borrower must provide, so the white-label app can show the right fields. */
            'kyc' => $this->when($this->resource->relationLoaded('kycSettings'), function (): array {
                // A lender that never chose its own settings uses the platform defaults.
                $settings = $this->kycSettings ?? InstitutionKycSetting::withDefaults();

                return [
                    'required_before_applying' => $settings->kyc_required,
                    'checks' => $settings->enabledChecks(),
                ];
            }),
        ];
    }
}
