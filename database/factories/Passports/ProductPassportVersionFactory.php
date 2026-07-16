<?php

namespace Database\Factories\Passports;

use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPassportVersion>
 */
class ProductPassportVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'passport_id' => ProductPassport::factory(),
            'status' => ProductPassportVersionStatus::Draft,
            'version_number' => null,
            'draft_revision' => 1,
            'schema_version' => '1.0',
            'payload' => json_encode([
                'draft_content' => [],
            ]),
            'content_checksum' => null,
            'published_at' => null,
            'published_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportVersionStatus::Draft,
            'version_number' => null,
            'published_at' => null,
            'published_by' => null,
            'content_checksum' => null,
        ]);
    }

    public function published(): static
    {
        $now = CarbonImmutable::now();

        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportVersionStatus::Published,
            'version_number' => 1,
            'published_at' => $now,
            'published_by' => User::factory(),
            'content_checksum' => hash('sha256', (string) json_encode($attributes['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'superseded_at' => null,
            'withdrawn_at' => null,
        ]);
    }

    public function superseded(): static
    {
        $now = CarbonImmutable::now();

        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportVersionStatus::Superseded,
            'version_number' => 1,
            'published_at' => $now->subDay(),
            'published_by' => User::factory(),
            'content_checksum' => hash('sha256', (string) json_encode($attributes['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'superseded_at' => $now,
        ]);
    }

    public function withdrawn(): static
    {
        $now = CarbonImmutable::now();

        return $this->state(fn (array $attributes) => [
            'status' => ProductPassportVersionStatus::Withdrawn,
            'version_number' => 1,
            'published_at' => $now->subDays(2),
            'published_by' => User::factory(),
            'content_checksum' => hash('sha256', (string) json_encode($attributes['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'withdrawn_at' => $now->subDay(),
        ]);
    }
}
