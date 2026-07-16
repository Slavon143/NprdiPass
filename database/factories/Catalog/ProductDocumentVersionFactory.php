<?php

namespace Database\Factories\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductDocumentVersion>
 */
class ProductDocumentVersionFactory extends Factory
{
    protected $model = ProductDocumentVersion::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => function () use ($company) {
                return ProductDocument::query()->forceCreate([
                    'uuid' => fake()->uuid(),
                    'company_id' => $company->id,
                    'product_id' => Product::query()->forceCreate([
                        'uuid' => fake()->uuid(),
                        'company_id' => $company->id,
                        'name' => fake()->word(),
                        'slug' => fake()->unique()->slug(),
                        'slug_normalized' => fake()->unique()->slug(),
                        'status' => ProductStatus::Active->value,
                        'created_by' => User::factory()->create()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->id,
                    'status' => ProductDocumentStatus::Active->value,
                    'created_by_user_id' => User::factory()->create()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->id;
            },
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'issuer_name' => fake()->optional()->company(),
            'issue_date' => null,
            'expires_at' => null,
            'original_filename' => 'test-document.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'companies/'.fake()->uuid().'/documents/'.fake()->uuid().'/v1.pdf',
            'created_by_user_id' => User::factory(),
        ];
    }

    public function forDocument(ProductDocument $document): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $document->company_id,
            'document_id' => $document->getKey(),
        ]);
    }

    public function certificate(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type' => ProductDocumentType::Certificate->value,
            'issuer_name' => fake()->company(),
            'issue_date' => now()->subDays(30),
        ]);
    }

    public function declarationOfConformity(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type' => ProductDocumentType::DeclarationOfConformity->value,
            'issuer_name' => fake()->company(),
            'issue_date' => now()->subDays(60),
        ]);
    }

    public function publicCandidate(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'issue_date' => now()->subYear()->subMonth(),
            'expires_at' => now()->subDays(5),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'issue_date' => now()->subYear(),
            'expires_at' => now()->addDays(10),
        ]);
    }

    public function english(): static
    {
        return $this->state(fn (array $attributes) => [
            'language' => 'en',
        ]);
    }
}
