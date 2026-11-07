<?php

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Enums\KycStatus;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state: a licensed community pharmacy trading for 6 years,
     * turning over ₦3m a month with ₦2m of expenses (₦1m net cash flow).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'user_id' => fn (array $attributes) => User::factory()->state([
                'institution_id' => $attributes['institution_id'],
                'role' => Role::Customer,
            ]),
            'business_name' => fake()->lastName().' Pharmacy',
            'business_type' => BusinessType::Pharmacy,
            'registration_number' => 'RC'.fake()->unique()->numerify('#######'),
            'licence_number' => 'PCN/'.fake()->numerify('#####'),
            'licence_expires_at' => now()->addYear()->toDateString(),
            'year_established' => now()->year - 6,
            'phone' => fake()->unique()->e164PhoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'staff_count' => 8,
            'monthly_revenue' => 300_000_000,
            'monthly_expenses' => 200_000_000,
            'kyc_status' => KycStatus::Verified,
            'kyc_checks' => ['bvn', 'nin'],
            'kyc_verified_at' => now(),
        ];
    }

    public function forInstitution(Institution $institution): static
    {
        return $this->state(fn (): array => ['institution_id' => $institution->id]);
    }

    public function ofType(BusinessType $type): static
    {
        return $this->state(fn (): array => ['business_type' => $type]);
    }

    /**
     * A new, thin-margin business whose cash flow cannot carry much debt.
     */
    public function struggling(): static
    {
        return $this->state(fn (): array => [
            'year_established' => now()->year,
            'monthly_revenue' => 40_000_000,
            'monthly_expenses' => 38_000_000,
        ]);
    }

    /**
     * A business that signed up but has not completed BVN / NIN verification.
     */
    public function kycPending(): static
    {
        return $this->state(fn (): array => ['kyc_status' => KycStatus::Pending, 'kyc_checks' => null, 'kyc_verified_at' => null]);
    }

    public function withExpiredLicence(): static
    {
        return $this->state(fn (): array => ['licence_expires_at' => now()->subDay()->toDateString()]);
    }
}
