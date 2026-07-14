<?php

namespace Database\Seeders;

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\ProductCategoryService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogDemoSeeder extends Seeder
{
    public function __construct(
        private readonly ProductAggregateCreator $aggregateCreator,
        private readonly ProductCategoryService $categoryService,
        private readonly CatalogIdentifierNormalizer $normalizer,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $company = Company::query()->where('name', 'NordiPass Demo AB')->first();
        $owner = User::query()->where('email', 'owner@nordipass.local')->first();

        if (! $company instanceof Company || ! $owner instanceof User
            || ! $company->memberships()->where('user_id', $owner->getKey())->exists()) {
            throw new RuntimeException('The dedicated local Demo Company and owner must be seeded first.');
        }

        DB::transaction(function () use ($company, $owner): void {
            $categories = $this->seedCategories($company, $owner);

            foreach ($this->products() as $specification) {
                $product = Product::query()
                    ->forCompany($company)
                    ->where('slug_normalized', $specification['slug'])
                    ->lockForUpdate()
                    ->first();

                if (! $product instanceof Product) {
                    $product = $this->aggregateCreator->create($owner, $company, [
                        'name' => $specification['name'],
                        'slug' => $specification['slug'],
                        'short_description' => $specification['short_description'],
                        'description' => null,
                        'brand' => $specification['brand'],
                        'manufacturer' => $specification['brand'],
                    ], [
                        'name' => $specification['default_name'],
                        'sku' => $specification['sku'],
                        'sku_normalized' => $this->normalizer->normalizeSku($specification['sku']),
                        'gtin' => null,
                        'mpn' => $specification['mpn'],
                        'sort_order' => 0,
                    ]);
                } else {
                    $product->forceFill([
                        'name' => $specification['name'],
                        'slug' => $specification['slug'],
                        'slug_normalized' => $specification['slug'],
                        'short_description' => $specification['short_description'],
                        'description' => null,
                        'brand' => $specification['brand'],
                        'manufacturer' => $specification['brand'],
                        'status' => ProductStatus::Draft,
                        'published_at' => null,
                        'primary_media_id' => null,
                        'updated_by' => $owner->getKey(),
                    ])->save();
                    $variant = $product->defaultVariant()->lockForUpdate()->first();

                    if ($variant === null) {
                        throw new RuntimeException("Demo product {$specification['slug']} has no default Variant.");
                    }

                    $variant->forceFill([
                        'name' => $specification['default_name'],
                        'sku' => $specification['sku'],
                        'sku_normalized' => $this->normalizer->normalizeSku($specification['sku']),
                        'gtin' => null,
                        'mpn' => $specification['mpn'],
                        'status' => ProductVariantStatus::Draft,
                        'sort_order' => 0,
                        'primary_media_id' => null,
                        'updated_by' => $owner->getKey(),
                    ])->save();
                }

                $primary = $categories[$specification['primary']];
                $additionalUuids = array_map(
                    fn (string $slug): string => $categories[$slug]->uuid,
                    $specification['additional'],
                );
                $this->categoryService->sync($company, $product, $primary->uuid, $additionalUuids);
                $this->seedAdditionalVariants(
                    $company,
                    $owner,
                    $product,
                    $specification['additional_variants'],
                );
            }

            $this->seedAttributes($company, $owner);
            $this->seedMedia($company, $owner);
        });
    }

    private function seedMedia(Company $company, User $owner): void
    {
        $fixtures = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'jpeg' => '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==',
        ];
        $specifications = [
            ['progrip-work-gloves', null, 'gloves-front.png', 'png', true],
            ['progrip-work-gloves', null, 'gloves-detail.jpg', 'jpeg', false],
            ['progrip-work-gloves', 'DEMO-GLOVE-PRO-M', 'gloves-medium.webp', 'webp', true],
            ['progrip-work-gloves', 'DEMO-GLOVE-PRO-L', 'gloves-large.png', 'png', true],
            ['reflective-safety-vest', null, 'vest-front.webp', 'webp', true],
            ['reflective-safety-vest', 'DEMO-VEST-YELLOW-L', 'vest-yellow-large.jpg', 'jpeg', true],
            ['industrial-led-work-lamp', null, 'lamp-front.jpg', 'jpeg', true],
            ['industrial-led-work-lamp', null, 'lamp-detail.png', 'png', false],
            ['industrial-led-work-lamp', 'DEMO-LAMP-40W', 'lamp-40w.webp', 'webp', true],
        ];

        foreach ($specifications as $position => [$slug, $sku, $filename, $format, $primary]) {
            $product = Product::query()->forCompany($company)->where('slug_normalized', $slug)->lockForUpdate()->firstOrFail();
            $variant = $sku === null ? null : ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->where('sku_normalized', $this->normalizer->normalizeSku($sku))->lockForUpdate()->firstOrFail();
            $bytes = base64_decode($fixtures[$format], true);
            $image = is_string($bytes) ? getimagesizefromstring($bytes) : false;
            if (! is_array($image)) {
                throw new RuntimeException("Invalid embedded demo {$format} image.");
            }
            $media = ProductMedia::query()->forCompany($company)->where('product_id', $product->getKey())
                ->where('product_variant_id', $variant?->getKey())->where('original_filename', $filename)->lockForUpdate()->first() ?? new ProductMedia;
            $uuid = $media->exists ? $media->uuid : (string) Str::uuid();
            $extension = $format === 'jpeg' ? 'jpg' : $format;
            $path = $company->uuid.'/products/'.$product->uuid.($variant ? '/variants/'.$variant->uuid : '').'/'.$uuid.'.'.$extension;
            Storage::disk((string) config('catalog.media.disk'))->put($path, $bytes, ['visibility' => 'private']);
            $media->forceFill(['uuid' => $uuid, 'company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $variant?->getKey(), 'original_filename' => $filename, 'storage_path' => $path, 'mime_type' => $image['mime'], 'size_bytes' => strlen($bytes), 'width' => (int) $image[0], 'height' => (int) $image[1], 'checksum_sha256' => hash('sha256', $bytes), 'alt_text' => pathinfo($filename, PATHINFO_FILENAME), 'caption' => null, 'sort_order' => (($position % 3) + 1) * 10, 'uploaded_by' => $owner->getKey()])->save();
            if ($primary) {
                ($variant ?? $product)->forceFill(['primary_media_id' => $media->getKey(), 'updated_by' => $owner->getKey()])->save();
            }
        }
    }

    private function seedAttributes(Company $company, User $owner): void
    {
        $specifications = [
            'size' => [AttributeDataType::Select, AttributeScope::Variant, null, true, [], ['s' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL']],
            'color' => [AttributeDataType::Select, AttributeScope::Variant, null, false, [], ['black' => 'Black', 'yellow' => 'Yellow', 'orange' => 'Orange']],
            'material' => [AttributeDataType::Select, AttributeScope::Product, null, false, [], ['nitrile' => 'Nitrile', 'polyester' => 'Polyester', 'steel' => 'Steel', 'abs_plastic' => 'ABS plastic']],
            'weight' => [AttributeDataType::Decimal, AttributeScope::Product, 'kg', false, ['min' => '0'], []],
            'power' => [AttributeDataType::Integer, AttributeScope::Variant, 'W', false, ['min' => '0'], []],
            'certifications' => [AttributeDataType::Multiselect, AttributeScope::Product, null, false, [], ['ce' => 'CE', 'en_388' => 'EN 388', 'en_iso_20471' => 'EN ISO 20471']],
        ];
        $definitions = [];
        $options = [];

        foreach ($specifications as $code => [$type, $scope, $unit, $required, $rules, $optionSpecs]) {
            $definition = AttributeDefinition::query()->forCompany($company)->where('code', $code)->lockForUpdate()->first() ?? new AttributeDefinition;
            $definition->forceFill([
                'company_id' => $company->getKey(),
                'name' => ucfirst($code),
                'code' => $code,
                'description' => null,
                'type' => $type,
                'scope' => $scope,
                'unit' => $unit,
                'required' => $required,
                'filterable' => false,
                'searchable' => false,
                'validation_rules' => $rules === [] ? null : $rules,
                'sort_order' => (count($definitions) + 1) * 10,
                'status' => AttributeDefinitionStatus::Active,
                'created_by' => $definition->exists ? $definition->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();
            $definitions[$code] = $definition;

            foreach ($optionSpecs as $optionCode => $label) {
                $option = AttributeOption::query()->forCompany($company)
                    ->where('attribute_definition_id', $definition->getKey())
                    ->where('code', $optionCode)
                    ->lockForUpdate()
                    ->first() ?? new AttributeOption;
                $option->forceFill([
                    'company_id' => $company->getKey(),
                    'attribute_definition_id' => $definition->getKey(),
                    'label' => $label,
                    'code' => $optionCode,
                    'sort_order' => (count($options[$code] ?? []) + 1) * 10,
                    'status' => AttributeOptionStatus::Active,
                ])->save();
                $options[$code][$optionCode] = $option;
            }
        }

        $productAssignments = [
            'progrip-work-gloves' => ['material' => 'nitrile', 'certifications' => ['ce', 'en_388']],
            'reflective-safety-vest' => ['material' => 'polyester', 'certifications' => ['ce', 'en_iso_20471']],
            'fire-extinguisher-6kg' => ['weight' => '6.0000', 'certifications' => ['ce']],
            'industrial-led-work-lamp' => ['material' => 'abs_plastic'],
        ];

        foreach ($productAssignments as $slug => $assignments) {
            $product = Product::query()->forCompany($company)->where('slug_normalized', $slug)->lockForUpdate()->firstOrFail();

            foreach ($assignments as $code => $assigned) {
                $this->seedProductAttributeValue($company, $product, $definitions[$code], $assigned, $options[$code] ?? []);
            }
        }

        $variantAssignments = [
            'DEMO-GLOVE-PRO-M' => ['size' => 'm', 'color' => 'black'],
            'DEMO-GLOVE-PRO-L' => ['size' => 'l', 'color' => 'black'],
            'DEMO-GLOVE-PRO-XL' => ['size' => 'xl', 'color' => 'black'],
            'DEMO-VEST-YELLOW-M' => ['size' => 'm', 'color' => 'yellow'],
            'DEMO-VEST-YELLOW-L' => ['size' => 'l', 'color' => 'yellow'],
            'DEMO-VEST-ORANGE-L' => ['size' => 'l', 'color' => 'orange'],
            'DEMO-LAMP-40W' => ['power' => 40],
            'DEMO-LAMP-60W' => ['power' => 60],
        ];

        foreach ($variantAssignments as $sku => $assignments) {
            $variant = ProductVariant::query()->forCompany($company)->where('sku_normalized', $this->normalizer->normalizeSku($sku))->lockForUpdate()->firstOrFail();

            foreach ($assignments as $code => $assigned) {
                $definition = $definitions[$code];
                $option = is_string($assigned) ? $this->attributeOption($options[$code] ?? [], $assigned) : null;
                $value = VariantAttributeValue::query()->forCompany($company)
                    ->where('product_variant_id', $variant->getKey())
                    ->where('attribute_definition_id', $definition->getKey())
                    ->lockForUpdate()
                    ->first() ?? new VariantAttributeValue;
                $value->forceFill([
                    'company_id' => $company->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'attribute_definition_id' => $definition->getKey(),
                    'value_text' => null,
                    'value_integer' => is_int($assigned) ? $assigned : null,
                    'value_decimal' => null,
                    'value_boolean' => null,
                    'value_date' => null,
                    'value_option_id' => $option?->getKey(),
                ])->save();
            }
        }
    }

    /** @param string|list<string> $assigned @param array<string, AttributeOption> $options */
    private function seedProductAttributeValue(Company $company, Product $product, AttributeDefinition $definition, string|array $assigned, array $options): void
    {
        $value = ProductAttributeValue::query()->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->lockForUpdate()
            ->first() ?? new ProductAttributeValue;
        $isMultiselect = $definition->type === AttributeDataType::Multiselect;
        $option = $definition->type === AttributeDataType::Select && is_string($assigned)
            ? $this->attributeOption($options, $assigned)
            : null;
        $value->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'attribute_definition_id' => $definition->getKey(),
            'value_text' => null,
            'value_integer' => null,
            'value_decimal' => $definition->type === AttributeDataType::Decimal ? $assigned : null,
            'value_boolean' => null,
            'value_date' => null,
            'value_option_id' => $option?->getKey(),
        ])->save();

        DB::table('product_attribute_value_options')->where('product_attribute_value_id', $value->getKey())->delete();

        if ($isMultiselect && is_array($assigned)) {
            DB::table('product_attribute_value_options')->insert(array_map(fn (string $code): array => [
                'company_id' => $company->getKey(),
                'attribute_definition_id' => $definition->getKey(),
                'product_attribute_value_id' => $value->getKey(),
                'attribute_option_id' => $this->attributeOption($options, $code)->getKey(),
                'created_at' => now(),
            ], $assigned));
        }
    }

    /** @param array<string, AttributeOption> $options */
    private function attributeOption(array $options, string $code): AttributeOption
    {
        $option = $options[$code] ?? null;

        if (! $option instanceof AttributeOption) {
            throw new RuntimeException("Demo attribute option {$code} is unavailable.");
        }

        return $option;
    }

    /**
     * @param  list<array{name: string, sku: string, mpn: string, sort_order: int}>  $specifications
     */
    private function seedAdditionalVariants(
        Company $company,
        User $owner,
        Product $product,
        array $specifications,
    ): void {
        foreach ($specifications as $specification) {
            $skuNormalized = $this->normalizer->normalizeSku($specification['sku']);
            $variant = ProductVariant::query()
                ->forCompany($company)
                ->where('sku_normalized', $skuNormalized)
                ->lockForUpdate()
                ->first();

            if ($variant instanceof ProductVariant
                && (int) $variant->product_id !== (int) $product->getKey()) {
                throw new RuntimeException("Demo SKU {$specification['sku']} belongs to another Product.");
            }

            $variant ??= new ProductVariant;
            $variant->forceFill([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'name' => $specification['name'],
                'sku' => $specification['sku'],
                'sku_normalized' => $skuNormalized,
                'gtin' => null,
                'mpn' => $this->normalizer->normalizeMpn($specification['mpn']),
                'status' => ProductVariantStatus::Draft,
                'sort_order' => $specification['sort_order'],
                'primary_media_id' => null,
                'created_by' => $variant->exists ? $variant->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();
        }
    }

    /** @return array<string, Category> */
    private function seedCategories(Company $company, User $owner): array
    {
        $tree = [
            ['slug' => 'arbetsklader', 'name' => 'Arbetskläder', 'parent' => null, 'sort' => 10],
            ['slug' => 'arbetshandskar', 'name' => 'Arbetshandskar', 'parent' => 'arbetsklader', 'sort' => 10],
            ['slug' => 'skyddsklader', 'name' => 'Skyddskläder', 'parent' => 'arbetsklader', 'sort' => 20],
            ['slug' => 'sakerhetsutrustning', 'name' => 'Säkerhetsutrustning', 'parent' => null, 'sort' => 20],
            ['slug' => 'brandskydd', 'name' => 'Brandskydd', 'parent' => 'sakerhetsutrustning', 'sort' => 10],
            ['slug' => 'horselskydd', 'name' => 'Hörselskydd', 'parent' => 'sakerhetsutrustning', 'sort' => 20],
            ['slug' => 'belysning', 'name' => 'Belysning', 'parent' => null, 'sort' => 30],
            ['slug' => 'arbetsbelysning', 'name' => 'Arbetsbelysning', 'parent' => 'belysning', 'sort' => 10],
        ];
        $categories = [];

        foreach ($tree as $node) {
            $parent = $node['parent'] === null ? null : $categories[$node['parent']];
            $category = Category::query()
                ->forCompany($company)
                ->where('slug_normalized', $node['slug'])
                ->first() ?? new Category;
            $category->forceFill([
                'company_id' => $company->getKey(),
                'parent_id' => $parent?->getKey(),
                'depth' => $parent === null ? 0 : $parent->depth + 1,
                'name' => $node['name'],
                'slug' => $node['slug'],
                'slug_normalized' => $node['slug'],
                'description' => null,
                'sort_order' => $node['sort'],
                'status' => CategoryStatus::Active,
                'created_by' => $category->exists ? $category->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();
            $categories[$node['slug']] = $category;
        }

        return $categories;
    }

    /**
     * @return list<array{
     *   name: string,
     *   slug: string,
     *   brand: string,
     *   short_description: string,
     *   primary: string,
     *   additional: list<string>,
     *   default_name: string,
     *   sku: string,
     *   mpn: string,
     *   additional_variants: list<array{name: string, sku: string, mpn: string, sort_order: int}>
     * }>
     */
    private function products(): array
    {
        return [
            ['name' => 'ProGrip Work Gloves', 'slug' => 'progrip-work-gloves', 'brand' => 'NordiSafe', 'short_description' => 'Durable demo work gloves for professional use.', 'primary' => 'arbetshandskar', 'additional' => ['arbetsklader'], 'default_name' => 'Medium', 'sku' => 'DEMO-GLOVE-PRO-M', 'mpn' => 'NS-GLOVE-PRO-M', 'additional_variants' => [
                ['name' => 'Large', 'sku' => 'DEMO-GLOVE-PRO-L', 'mpn' => 'NS-GLOVE-PRO-L', 'sort_order' => 10],
                ['name' => 'Extra Large', 'sku' => 'DEMO-GLOVE-PRO-XL', 'mpn' => 'NS-GLOVE-PRO-XL', 'sort_order' => 20],
            ]],
            ['name' => 'Reflective Safety Vest', 'slug' => 'reflective-safety-vest', 'brand' => 'NordiSafe', 'short_description' => 'High-visibility demo safety vest.', 'primary' => 'skyddsklader', 'additional' => ['arbetsklader'], 'default_name' => 'Yellow / Large', 'sku' => 'DEMO-VEST-YELLOW-L', 'mpn' => 'NS-VEST-YL-L', 'additional_variants' => [
                ['name' => 'Yellow / Medium', 'sku' => 'DEMO-VEST-YELLOW-M', 'mpn' => 'NS-VEST-YL-M', 'sort_order' => 10],
                ['name' => 'Orange / Large', 'sku' => 'DEMO-VEST-ORANGE-L', 'mpn' => 'NS-VEST-OR-L', 'sort_order' => 20],
            ]],
            ['name' => 'Fire Extinguisher 6 kg', 'slug' => 'fire-extinguisher-6kg', 'brand' => 'SafeGuard', 'short_description' => 'Six kilogram demo fire extinguisher.', 'primary' => 'brandskydd', 'additional' => ['sakerhetsutrustning'], 'default_name' => '6 kg', 'sku' => 'DEMO-FIRE-6KG', 'mpn' => 'SG-FE-6KG', 'additional_variants' => []],
            ['name' => 'Professional Ear Defenders', 'slug' => 'professional-ear-defenders', 'brand' => 'SoundShield', 'short_description' => 'Professional demo hearing protection.', 'primary' => 'horselskydd', 'additional' => ['sakerhetsutrustning'], 'default_name' => 'Professional', 'sku' => 'DEMO-EAR-PRO', 'mpn' => 'SS-EAR-PRO', 'additional_variants' => []],
            ['name' => 'Industrial LED Work Lamp', 'slug' => 'industrial-led-work-lamp', 'brand' => 'NordiLight', 'short_description' => 'Industrial demo LED work lamp.', 'primary' => 'arbetsbelysning', 'additional' => ['belysning'], 'default_name' => '40 W', 'sku' => 'DEMO-LAMP-40W', 'mpn' => 'NL-WORK-40', 'additional_variants' => [
                ['name' => '60 W', 'sku' => 'DEMO-LAMP-60W', 'mpn' => 'NL-WORK-60', 'sort_order' => 10],
            ]],
        ];
    }
}
