<?php

namespace Database\Seeders;

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\ProductCategoryService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        });
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
