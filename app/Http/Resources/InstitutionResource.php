<?php

namespace App\Http\Resources;

use App\Models\Institution;
use App\Models\InstitutionKycSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Institution
 */
class InstitutionResource extends JsonResource
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
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'suspended_at' => $this->suspended_at,
            /** 10MG's fee on this lender's loans (platform default when `is_default`). */
            'platform_fee' => [
                'rate_bps' => $this->platformFeeRateBps(),
                'bearer' => $this->platformFeeBearer(),
                'is_default' => ! $this->hasCustomPlatformFee(),
            ],
            'branding' => new BrandingResource($this->whenLoaded('branding')),
            /** The lender's KYC policy (platform defaults if it never set its own). Included on the detail view. */
            'kyc_settings' => $this->when(
                $this->resource->relationLoaded('kycSettings'),
                fn (): KycSettingsResource => new KycSettingsResource($this->kycSettings ?? InstitutionKycSetting::withDefaults()),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
