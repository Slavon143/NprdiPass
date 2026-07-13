<?php

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyMembership>
 */
class CompanyMembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'role' => CompanyRole::Viewer,
            'is_owner' => false,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => CompanyRole::Owner,
            'is_owner' => true,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => CompanyRole::Admin,
            'is_owner' => false,
        ]);
    }

    public function editor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => CompanyRole::Editor,
            'is_owner' => false,
        ]);
    }

    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => CompanyRole::Viewer,
            'is_owner' => false,
        ]);
    }
}
