<?php

namespace App\Http\Resources;

use App\Models\InstitutionKycSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lender's KYC policy.
 *
 * @mixin InstitutionKycSetting
 */
class KycSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kyc_required' => $this->kyc_required,
            'bvn_check_enabled' => $this->bvn_check_enabled,
            'nin_check_enabled' => $this->nin_check_enabled,
            'phone_match_required' => $this->phone_match_required,
            'enabled_checks' => $this->enabledChecks(),
            'updated_at' => $this->updated_at,
        ];
    }
}
