<?php

namespace Database\Factories;

use App\Enums\DirectorRole;
use App\Models\Customer;
use App\Models\CustomerDirector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDirector>
 */
class CustomerDirectorFactory extends Factory
{
    /**
     * Define the model's default state: a verified owner of a borrowing business.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'institution_id' => fn (array $attributes) => Customer::query()->withoutGlobalScopes()->findOrFail($attributes['customer_id'])->institution_id,
            'role' => DirectorRole::Owner,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-25 years')->format('Y-m-d'),
            'phone' => fake()->e164PhoneNumber(),
            'bvn_reference' => 'NIBSS-'.fake()->bothify('????########'),
            'bvn_verified_at' => now(),
            'nin_reference' => 'NIMC-'.fake()->bothify('????########'),
            'nin_verified_at' => now(),
        ];
    }

    /**
     * Encrypt and fingerprint the numbers the same way the application does.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CustomerDirector $director): void {
            if ($director->getAttribute('bvn_hash') === null) {
                $director->setIdentityNumber('bvn', fake()->unique()->numerify('22#########'));
            }

            if ($director->getAttribute('nin_hash') === null) {
                $director->setIdentityNumber('nin', fake()->unique()->numerify('###########'));
            }
        });
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->id, 'institution_id' => $customer->institution_id]);
    }
}
