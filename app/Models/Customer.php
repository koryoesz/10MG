<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\DirectorRole;
use App\Enums\KycStatus;
use App\Support\Tenancy\BelongsToInstitution;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A borrowing healthcare business (pharmacy, clinic, hospital, ...). The linked user is the
 * owner or primary contact who acts on the business's behalf.
 */
#[Fillable([
    'business_name', 'business_type', 'registration_number', 'licence_number', 'licence_expires_at',
    'year_established', 'phone', 'address', 'city', 'state', 'staff_count',
    'monthly_revenue', 'monthly_expenses',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToInstitution, HasFactory, HasUlids;

    /**
     * Mirrors the column default so a freshly created business reports its KYC status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kyc_status' => KycStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'licence_expires_at' => 'immutable_date',
            'year_established' => 'integer',
            'staff_count' => 'integer',
            'monthly_revenue' => 'integer',
            'monthly_expenses' => 'integer',
            'kyc_status' => KycStatus::class,
            'kyc_checks' => 'array',
            'kyc_verified_at' => 'immutable_datetime',
        ];
    }

    public function yearsInOperation(?CarbonImmutable $asOf = null): int
    {
        return max(0, ($asOf ?? CarbonImmutable::today())->year - $this->year_established);
    }

    public function netMonthlyCashFlow(): int
    {
        return $this->monthly_revenue - $this->monthly_expenses;
    }

    public function hasValidLicence(?CarbonImmutable $asOf = null): bool
    {
        return $this->licence_expires_at->greaterThanOrEqualTo($asOf ?? CarbonImmutable::today());
    }

    /**
     * Checks the lender currently runs that this business has not passed yet.
     *
     * @return list<string>
     */
    public function outstandingKycChecks(InstitutionKycSetting $settings): array
    {
        return array_values(array_diff($settings->enabledChecks(), $this->kyc_checks ?? []));
    }

    /**
     * Whether the business may apply for a loan under the lender's current KYC settings.
     */
    public function meetsKycRequirements(InstitutionKycSetting $settings): bool
    {
        return ! $settings->kyc_required || $this->outstandingKycChecks($settings) === [];
    }

    /**
     * KYC status judged against the lender's current settings. A business verified for BVN
     * only becomes `pending` again if the lender later switches on the NIN check.
     */
    public function kycStatusUnder(InstitutionKycSetting $settings): KycStatus
    {
        return match (true) {
            $settings->enabledChecks() === [] => KycStatus::NotRequired,
            $this->outstandingKycChecks($settings) === [] => KycStatus::Verified,
            $this->kyc_status === KycStatus::Failed => KycStatus::Failed,
            default => KycStatus::Pending,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * @return HasMany<CustomerDirector, $this>
     */
    public function directors(): HasMany
    {
        return $this->hasMany(CustomerDirector::class);
    }

    /**
     * The owner whose identity gates borrowing.
     *
     * @return HasOne<CustomerDirector, $this>
     */
    public function owner(): HasOne
    {
        return $this->hasOne(CustomerDirector::class)->where('role', DirectorRole::Owner);
    }
}
