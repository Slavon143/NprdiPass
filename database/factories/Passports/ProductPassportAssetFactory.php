<?php

namespace Database\Factories\Passports;

use App\Enums\Passports\ProductPassportAssetKind;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPassportAsset>
 */
class ProductPassportAssetFactory extends Factory
{
    public function definition(): array
    {
        $mediaUuid = (string) str()->uuid();

        return [
            'company_id' => fn () => ProductPassport::factory()->create()->company_id,
            'passport_id' => ProductPassport::factory(),
            'version_id' => ProductPassportVersion::factory()->draft(),
            'kind' => ProductPassportAssetKind::ProductMedia,
            'source_resource_uuid' => $mediaUuid,
            'role' => 'primary',
            'sort_order' => 10,
            'language' => null,
            'mime_type' => 'image/jpeg',
            'file_extension' => 'jpg',
            'size_bytes' => 102400,
            'width' => 1200,
            'height' => 800,
            'checksum_sha256' => hash('sha256', fake()->sha256()),
            'storage_key' => 'companies/'.str()->uuid().'/passports/'.str()->uuid().'/versions/1/'.$mediaUuid.'.jpg',
            'is_public' => true,
        ];
    }

    public function productMedia(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => ProductPassportAssetKind::ProductMedia]);
    }

    public function variantMedia(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => ProductPassportAssetKind::VariantMedia]);
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes) => ['is_public' => true]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => ['is_public' => false]);
    }
}
