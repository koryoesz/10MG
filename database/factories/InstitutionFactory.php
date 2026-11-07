<?php

namespace Database\Factories;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\InstitutionBranding;
use App\Models\InstitutionKycSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state: an active institution.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Microfinance';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => fake()->companyEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'country_code' => 'NG',
            'currency' => 'NGN',
            'status' => InstitutionStatus::Active,
            'approved_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => InstitutionStatus::Pending, 'approved_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => InstitutionStatus::Suspended, 'suspended_at' => now()]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Institution $institution): void {
            $branding = new InstitutionBranding(['display_name' => $institution->name]);
            $branding->institution_id = $institution->id;
            $branding->save();

            $kycSettings = InstitutionKycSetting::withDefaults();
            $kycSettings->institution_id = $institution->id;
            $kycSettings->save();
        });
    }
}
