<?php

namespace App\Http\Resources;

use App\Models\InstitutionBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InstitutionBranding
 */
class BrandingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->display_name,
            'logo_url' => $this->logo_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
            'website_url' => $this->website_url,
            'custom_domain' => $this->custom_domain,
            'updated_at' => $this->updated_at,
        ];
    }
}
