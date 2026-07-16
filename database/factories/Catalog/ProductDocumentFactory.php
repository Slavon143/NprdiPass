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
 * @extends Factory<ProductDocument>
 */
class ProductDocumentFactory extends Factory
{
    protected $model = ProductDocument::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
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
            'created_by_user_id' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductDocumentStatus::Active->value,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductDocumentStatus::Archived->value,
            'archived_at' => now(),
        ]);
    }

    public function withInitialVersion(?array $versionAttributes = null): static
    {
        return $this->afterCreating(function (ProductDocument $document) use ($versionAttributes): void {
            $version = new ProductDocumentVersion;
            $version->forceFill(array_merge([
                'uuid' => fake()->uuid(),
                'company_id' => $document->company_id,
                'document_id' => $document->getKey(),
                'version_number' => 1,
                'document_type' => ProductDocumentType::Instruction->value,
                'title' => fake()->sentence(3),
                'language' => 'sv',
                'visibility' => ProductDocumentVisibility::Internal->value,
                'original_filename' => 'test.pdf',
                'mime_type' => 'application/pdf',
                'file_extension' => 'pdf',
                'size_bytes' => 1024,
                'checksum_sha256' => str_repeat('a', 64),
                'storage_key' => 'companies/'.fake()->uuid().'/test.pdf',
                'created_by_user_id' => User::factory()->create()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ], $versionAttributes ?? []))->save();

            $document->forceFill(['current_version_id' => $version->getKey()])->save();
        });
    }

    public function configure(): static
    {
        return $this;
    }
}
