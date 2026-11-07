<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: an institution administrator.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::InstitutionAdmin,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function platformAdmin(): static
    {
        return $this->state(fn (): array => ['institution_id' => null, 'role' => Role::PlatformAdmin]);
    }

    public function forInstitution(Institution $institution): static
    {
        return $this->state(fn (): array => ['institution_id' => $institution->id]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
