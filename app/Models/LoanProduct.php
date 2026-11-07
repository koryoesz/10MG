<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\FeeCalculation;
use App\Enums\InterestMethod;
use App\Enums\RepaymentFrequency;
use App\Support\Tenancy\BelongsToInstitution;
use Database\Factories\LoanProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'code', 'description', 'currency', 'min_amount', 'max_amount', 'min_tenor', 'max_tenor',
    'interest_rate_bps', 'interest_method', 'repayment_frequency', 'grace_period_days',
    'late_fee_type', 'late_fee_value', 'min_credit_score', 'eligible_business_types', 'is_active',
])]
class LoanProduct extends Model
{
    /** @use HasFactory<LoanProductFactory> */
    use BelongsToInstitution, HasFactory, HasUlids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'min_tenor' => 'integer',
            'max_tenor' => 'integer',
            'interest_rate_bps' => 'integer',
            'interest_method' => InterestMethod::class,
            'repayment_frequency' => RepaymentFrequency::class,
            'grace_period_days' => 'integer',
            'late_fee_type' => FeeCalculation::class,
            'late_fee_value' => 'integer',
            'min_credit_score' => 'integer',
            'eligible_business_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Products may be restricted to certain kinds of healthcare provider; null means all.
     */
    public function isAvailableTo(BusinessType $type): bool
    {
        return $this->eligible_business_types === null || in_array($type->value, $this->eligible_business_types, true);
    }

    /**
     * @return HasMany<LoanProductFee, $this>
     */
    public function fees(): HasMany
    {
        return $this->hasMany(LoanProductFee::class);
    }
}
