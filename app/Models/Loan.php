<?php

namespace App\Models;

use App\Enums\FeeCalculation;
use App\Enums\InterestMethod;
use App\Enums\LoanPurpose;
use App\Enums\LoanStatus;
use App\Enums\PlatformFeeBearer;
use App\Enums\RepaymentFrequency;
use App\Exceptions\Api\InvalidLoanState;
use App\Support\Tenancy\BelongsToInstitution;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A loan and its terms. Pricing fields are a snapshot of the product taken at application
 * time; balances are only changed by Actions while holding a row lock.
 *
 * No attributes are mass assignable: every field is set deliberately by an Action.
 */
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToInstitution, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'purpose' => LoanPurpose::class,
            'interest_method' => InterestMethod::class,
            'repayment_frequency' => RepaymentFrequency::class,
            'fees_snapshot' => 'array',
            'platform_fee_bps' => 'integer',
            'platform_fee_bearer' => PlatformFeeBearer::class,
            'platform_fee_amount' => 'integer',
            'grace_period_days' => 'integer',
            'late_fee_type' => FeeCalculation::class,
            'late_fee_value' => 'integer',
            'principal_amount' => 'integer',
            'tenor' => 'integer',
            'interest_rate_bps' => 'integer',
            'total_interest' => 'integer',
            'upfront_fees' => 'integer',
            'capitalized_fees' => 'integer',
            'penalty_fees' => 'integer',
            'net_disbursement_amount' => 'integer',
            'total_repayable' => 'integer',
            'amount_paid' => 'integer',
            'outstanding_balance' => 'integer',
            'reviewed_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'first_due_date' => 'immutable_date',
            'maturity_date' => 'immutable_date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Move the loan through its state machine, rejecting illegal transitions.
     *
     * @throws InvalidLoanState
     */
    public function transitionTo(LoanStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidLoanState(
                "Cannot move loan from {$this->status->value} to {$target->value}.",
                ['current_status' => $this->status->value, 'requested_status' => $target->value],
            );
        }

        $this->status = $target;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<LoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id')->withTrashed();
    }

    /**
     * @return HasOne<CreditAssessment, $this>
     */
    public function creditAssessment(): HasOne
    {
        return $this->hasOne(CreditAssessment::class);
    }

    /**
     * @return HasMany<LoanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<LoanTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LoanTransaction::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
