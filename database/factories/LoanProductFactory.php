<?php

namespace Database\Factories;

use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use App\Enums\InterestMethod;
use App\Enums\RepaymentFrequency;
use App\Models\Institution;
use App\Models\LoanProduct;
use App\Models\LoanProductFee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoanProduct>
 */
class LoanProductFactory extends Factory
{
    /**
     * Define the model's default state: ₦10k–₦1m working capital, 1–12 monthly installments, 24% p.a. reducing balance.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => 'Working Capital Loan',
            'code' => 'WC-'.Str::upper(Str::random(6)),
            'description' => 'Short-term working capital for healthcare providers.',
            'currency' => 'NGN',
            'min_amount' => 1_000_000,
            'max_amount' => 100_000_000,
            'min_tenor' => 1,
            'max_tenor' => 12,
            'interest_rate_bps' => 2400,
            'interest_method' => InterestMethod::ReducingBalance,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'grace_period_days' => 3,
            'late_fee_type' => FeeCalculation::Percentage,
            'late_fee_value' => 500,
            'min_credit_score' => null,
            'eligible_business_types' => null,
            'is_active' => true,
        ];
    }

    public function forInstitution(Institution $institution): static
    {
        return $this->state(fn (): array => ['institution_id' => $institution->id, 'currency' => $institution->currency]);
    }

    public function flat(): static
    {
        return $this->state(fn (): array => ['interest_method' => InterestMethod::Flat]);
    }

    /**
     * A 1% processing fee deducted at disbursement plus a ₦500 insurance fee spread over installments.
     */
    public function withStandardFees(): static
    {
        return $this->afterCreating(function (LoanProduct $product): void {
            foreach ([
                ['name' => 'Processing fee', 'calculation' => FeeCalculation::Percentage, 'value' => 100, 'charge_type' => FeeChargeType::Upfront],
                ['name' => 'Credit life insurance', 'calculation' => FeeCalculation::Fixed, 'value' => 50_000, 'charge_type' => FeeChargeType::Capitalized],
            ] as $feeData) {
                $fee = new LoanProductFee($feeData);
                $fee->institution_id = $product->institution_id;
                $product->fees()->save($fee);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
