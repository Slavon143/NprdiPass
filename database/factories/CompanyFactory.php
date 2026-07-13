<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->optional()->company(),
            'organization_number' => fake()->optional()->numerify('######-####'),
            'country_code' => fake()->countryCode(),
            'billing_email' => fake()->optional()->safeEmail(),
            'status' => CompanyStatus::Active,
            'settings' => ['locale' => 'en'],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompanyStatus::Active,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompanyStatus::Suspended,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompanyStatus::Archived,
        ]);
    }

    public function swedish(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'SE',
            'organization_number' => fake()->numerify('######-####'),
        ]);
    }
}
