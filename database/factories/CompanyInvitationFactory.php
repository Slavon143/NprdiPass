<?php

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyInvitation>
 */
class CompanyInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => CompanyRole::Viewer,
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'invited_by' => User::factory(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
            'accepted_at' => null,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);
    }
}
