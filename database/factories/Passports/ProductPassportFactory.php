<?php

namespace Database\Factories\Passports;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<ProductPassport>
 */
class ProductPassportFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'public_id' => Uuid::uuid7()->toString(),
            'company_id' => $company->id,
            'product_id' => Product::query()->forceCreate([
                'uuid' => (string) str()->uuid(),
                'company_id' => $company->id,
                'name' => fake()->word().' '.fake()->randomNumber(3),
                'slug' => 'test-'.fake()->unique()->slug(),
                'slug_normalized' => 'test-'.fake()->unique()->slug(),
                'status' => ProductStatus::Active->value,
                'created_by' => User::factory()->create()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->id,
            'status' => ProductPassportStatus::Draft,
            'default_language' => 'sv',
            'enabled_languages' => json_encode(['sv', 'en']),
            'created_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ProductPassportStatus::Draft]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportStatus::Published,
            'first_published_at' => now(),
            'last_published_at' => now(),
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportStatus::Unpublished,
            'first_published_at' => now(),
            'last_published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportStatus::Archived,
            'first_published_at' => now(),
            'last_published_at' => now(),
            'archived_at' => now(),
        ]);
    }
}
