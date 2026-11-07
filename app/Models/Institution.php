<?php

namespace App\Models;

use App\Enums\InstitutionStatus;
use App\Enums\PlatformFeeBearer;
use App\Support\Tenancy\InstitutionScope;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'email', 'phone', 'country_code', 'currency'])]
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InstitutionStatus::class,
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'platform_fee_bps' => 'integer',
            'platform_fee_bearer' => PlatformFeeBearer::class,
        ];
    }

    /**
     * 10MG's fee rate for this lender, falling back to the platform default.
     */
    public function platformFeeRateBps(): int
    {
        return $this->platform_fee_bps ?? (int) config('lending.platform_fee.rate_bps');
    }

    public function platformFeeBearer(): PlatformFeeBearer
    {
        return $this->platform_fee_bearer ?? PlatformFeeBearer::from(config('lending.platform_fee.bearer'));
    }

    /**
     * Whether the lender has its own platform fee terms rather than the platform default.
     */
    public function hasCustomPlatformFee(): bool
    {
        return $this->platform_fee_bps !== null;
    }

    public function isActive(): bool
    {
        return $this->status === InstitutionStatus::Active;
    }

    /**
     * The foreign key already pins this to the institution, so the tenant scope is not needed
     * (and would hide branding when the institution is loaded outside its own context).
     *
     * @return HasOne<InstitutionBranding, $this>
     */
    public function branding(): HasOne
    {
        return $this->hasOne(InstitutionBranding::class)->withoutGlobalScope(InstitutionScope::class);
    }

    /**
     * The lender's KYC choices. Unscoped for the same reason as `branding()`.
     *
     * @return HasOne<InstitutionKycSetting, $this>
     */
    public function kycSettings(): HasOne
    {
        return $this->hasOne(InstitutionKycSetting::class)->withoutGlobalScope(InstitutionScope::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
