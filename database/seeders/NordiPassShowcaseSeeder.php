<?php

namespace Database\Seeders;

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportAssetKind;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Enums\UserStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\CanonicalJsonEncoder;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\DppSchemaRegistry;
use App\Services\Passports\PassportSnapshotBuilder;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Database\DemoAssets\DemoAssetProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NordiPassShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        require_once database_path('demo-assets/DemoAssetProvider.php');

        $company = $this->seedCompany();
        $users = $this->seedUsers($company);
        $owner = $users['demo.owner@nordipass.test'];

        DB::transaction(function () use ($company, $owner): void {
            $this->seedAttributes($company, $owner);
            $categories = $this->seedCategories($company, $owner);
            $products = $this->seedProducts($company, $owner, $categories);
            $this->seedAttributeValues($company, $products);
            $this->seedProductMedia($company, $owner, $products);
            $documentReferences = $this->seedProductDocuments($company, $owner, $products);
            $this->seedPassports($company, $owner, $products, $documentReferences);
        });

        if (! app()->runningUnitTests() && $this->command !== null) {
            $this->command->info('Showcase dataset seeded successfully.');
        }
    }

    private function seedCompany(): Company
    {
        $company = Company::query()->where('name', 'NordiPass Demo AB')->first() ?? new Company;

        $company->forceFill([
            'name' => 'NordiPass Demo AB',
            'legal_name' => 'NordiPass Demo AB',
            'organization_number' => '559000-0001',
            'country_code' => 'SE',
            'billing_email' => 'billing.demo@nordipass.local',
            'status' => CompanyStatus::Active,
            'settings' => ['locale' => 'sv'],
        ])->save();

        return $company;
    }

    private function seedUsers(Company $company): array
    {
        $emails = [
            'demo.owner@nordipass.test' => ['Owner', CompanyRole::Owner],
            'demo.admin@nordipass.test' => ['Admin', CompanyRole::Admin],
            'demo.editor@nordipass.test' => ['Editor', CompanyRole::Editor],
            'demo.viewer@nordipass.test' => ['Viewer', CompanyRole::Viewer],
        ];

        $password = config('passports.demo_password') ?: 'password';

        $users = [];

        foreach ($emails as $email => [$name, $role]) {
            $user = User::query()->where('email', $email)->first() ?? new User;

            if (! $user->exists) {
                $user->forceFill([
                    'email' => $email,
                    'name' => "Demo {$name}",
                    'password' => Hash::make($password),
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ])->save();
            }

            CompanyMembership::query()->firstOrCreate(
                ['company_id' => $company->getKey(), 'user_id' => $user->getKey()],
                ['role' => $role, 'joined_at' => now()],
            );

            $users[$email] = $user;
        }

        return $users;
    }

    private function seedCategories(Company $company, User $owner): array
    {
        $tree = [
            ['slug' => 'industrial-equipment', 'name' => 'Industrial Equipment', 'parent' => null],
            ['slug' => 'lighting', 'name' => 'Lighting', 'parent' => 'industrial-equipment'],
            ['slug' => 'fire-safety', 'name' => 'Fire Safety', 'parent' => 'industrial-equipment'],
            ['slug' => 'power-tools', 'name' => 'Power Tools', 'parent' => 'industrial-equipment'],
            ['slug' => 'ppe', 'name' => 'Personal Protective Equipment', 'parent' => null],
            ['slug' => 'protective-clothing', 'name' => 'Protective Clothing', 'parent' => 'ppe'],
            ['slug' => 'protective-gloves', 'name' => 'Protective Gloves', 'parent' => 'ppe'],
        ];

        $categories = [];

        foreach ($tree as $node) {
            $parent = $node['parent'] === null ? null : ($categories[$node['parent']] ?? null);

            $category = Category::query()->forCompany($company)
                ->where('slug_normalized', $node['slug'])->first() ?? new Category;

            $category->forceFill([
                'company_id' => $company->getKey(),
                'parent_id' => $parent?->getKey(),
                'depth' => $parent === null ? 0 : $parent->depth + 1,
                'name' => $node['name'],
                'slug' => $node['slug'],
                'slug_normalized' => $node['slug'],
                'description' => null,
                'sort_order' => 10,
                'status' => CategoryStatus::Active,
                'created_by' => $category->exists ? $category->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();

            $categories[$node['slug']] = $category;
        }

        return $categories;
    }

    private function seedAttributes(Company $company, User $owner): void
    {
        $specs = [
            'power' => ['Power', 'select', 'product', ['40W' => '40W', '60W' => '60W']],
            'voltage' => ['Voltage', 'text', 'product', []],
            'ingress_protection' => ['Ingress Protection', 'select', 'product',
                ['ip44' => 'IP44', 'ip54' => 'IP54', 'ip65' => 'IP65', 'ip67' => 'IP67']],
            'light_output' => ['Light Output', 'text', 'product', []],
            'weight' => ['Weight', 'text', 'product', []],
            'colour' => ['Colour', 'select', 'product',
                ['black' => 'Black', 'yellow' => 'Yellow', 'red' => 'Red', 'orange' => 'Orange']],
            'size' => ['Size', 'select', 'variant', ['s' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL']],
            'material' => ['Material', 'text', 'product', []],
            'fire_rating' => ['Fire Rating', 'text', 'product', []],
            'battery_voltage' => ['Battery Voltage', 'text', 'product', []],
            'repairability' => ['Repairability', 'text', 'product', []],
            'warranty_period' => ['Warranty Period', 'text', 'product', []],
        ];

        foreach ($specs as $code => [$name, $type, $scope, $optionSpecs]) {
            $typeEnum = $type === 'select' ? AttributeDataType::Select : AttributeDataType::Text;
            /** @var string $scope */
            $scopeEnum = $scope === 'variant' ? AttributeScope::Variant : AttributeScope::Product;

            $def = AttributeDefinition::query()->forCompany($company)->where('code', $code)->first() ?? new AttributeDefinition;

            $def->forceFill([
                'company_id' => $company->getKey(),
                'name' => $name,
                'code' => $code,
                'description' => null,
                'type' => $typeEnum,
                'scope' => $scopeEnum,
                'unit' => null,
                'required' => false,
                'filterable' => false,
                'searchable' => false,
                'validation_rules' => null,
                'sort_order' => 10,
                'status' => AttributeDefinitionStatus::Active,
                'created_by' => $def->exists ? $def->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();

            foreach ($optionSpecs as $optCode => $label) {
                $opt = AttributeOption::query()->forCompany($company)
                    ->where('attribute_definition_id', $def->getKey())
                    ->where('code', $optCode)->first() ?? new AttributeOption;

                $opt->forceFill([
                    'company_id' => $company->getKey(),
                    'attribute_definition_id' => $def->getKey(),
                    'label' => $label,
                    'code' => $optCode,
                    'sort_order' => 10,
                    'status' => AttributeOptionStatus::Active,
                ])->save();
            }
        }
    }

    private function seedProducts(Company $company, User $owner, array $categories): array
    {
        $specs = [
            'industrial-led-work-lamp' => [
                'name' => 'Industrial LED Work Lamp 40 W', 'brand' => 'NordiLight',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'primary' => 'lighting', 'additional' => ['industrial-equipment'],
                'desc' => 'Portable industrial LED work lamp designed for construction, workshops and temporary work areas.',
                'variant' => ['40 W / EU Plug', 'DEMO-LAMP-40W', 'NL-WORK-40'],
                'extra' => [['40 W / UK Plug', 'DEMO-LAMP-40UK', 'NL-WORK-40UK'], ['60 W / EU Plug', 'DEMO-LAMP-60W', 'NL-WORK-60']],
            ],
            'fire-extinguisher-6kg' => [
                'name' => 'Fire Extinguisher 6 kg', 'brand' => 'SafeGuard',
                'manufacturer' => 'SafeGuard AB',
                'primary' => 'fire-safety', 'additional' => ['industrial-equipment'],
                'desc' => 'Six kilogram dry powder fire extinguisher for industrial use.',
                'variant' => ['6 kg', 'DEMO-FE-6KG', 'SG-FE-6KG'], 'extra' => [],
            ],
            'reflective-safety-vest' => [
                'name' => 'Reflective Safety Vest', 'brand' => 'NordiSafe',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'primary' => 'protective-clothing', 'additional' => ['ppe'],
                'desc' => 'High-visibility reflective safety vest for construction and road work.',
                'variant' => ['Yellow / L', 'DEMO-VEST-YL-L', 'NS-VEST-YL-L'],
                'extra' => [['Yellow / M', 'DEMO-VEST-YL-M', 'NS-VEST-YL-M']],
                'status' => ProductStatus::Draft,
            ],
            'progrip-work-gloves' => [
                'name' => 'ProGrip Protective Work Gloves', 'brand' => 'NordiSafe',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'primary' => 'protective-gloves', 'additional' => ['ppe'],
                'desc' => 'Durable protective work gloves with abrasion resistance.',
                'variant' => ['Medium', 'DEMO-GLOVE-M', 'NS-GLOVE-M'],
                'extra' => [['Large', 'DEMO-GLOVE-L', 'NS-GLOVE-L']],
            ],
            'cordless-drill-18v' => [
                'name' => 'NordiTool Cordless Drill 18 V', 'brand' => 'NordiTool',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'primary' => 'power-tools', 'additional' => ['industrial-equipment'],
                'desc' => 'Cordless drill with 18V lithium-ion battery.',
                'variant' => ['18 V', 'DEMO-DRILL-18V', 'NT-DRILL-18V'], 'extra' => [],
                'status' => ProductStatus::Archived,
            ],
            'industrial-storage-case' => [
                'name' => 'Industrial Storage Case', 'brand' => 'NordiTool',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'primary' => 'power-tools', 'additional' => ['industrial-equipment'],
                'desc' => 'Heavy-duty industrial storage case for tools and accessories.',
                'variant' => ['Standard', 'DEMO-CASE-STD', 'NT-CASE-STD'], 'extra' => [],
                'status' => ProductStatus::Draft,
            ],
        ];

        $products = [];

        foreach ($specs as $slug => $spec) {
            $product = Product::query()->forCompany($company)
                ->where('slug_normalized', $slug)->first();

            if (! $product) {
                $product = Product::query()->withTrashed()->forCompany($company)
                    ->where('slug_normalized', $slug)->first();
            }

            if (! $product) {
                $product = new Product;
            }

            $product->forceFill([
                'company_id' => $company->getKey(),
                'name' => $spec['name'],
                'slug' => $slug,
                'slug_normalized' => $slug,
                'short_description' => $spec['desc'],
                'brand' => $spec['brand'],
                'manufacturer' => $spec['manufacturer'],
                'status' => $spec['status'] ?? ProductStatus::Active,
                'published_at' => ($spec['status'] ?? ProductStatus::Active) === ProductStatus::Active
                    ? ($product->published_at ?? now())
                    : null,
                'created_by' => $product->exists ? $product->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
                'deleted_at' => null,
            ])->save();

            $vSpec = $spec['variant'];
            $gtins = $this->demoGtins();

            $variant = ProductVariant::query()->forCompany($company)
                ->where('product_id', $product->getKey())
                ->where('sku_normalized', Str::lower($vSpec[1]))->first();

            if (! $variant) {
                $variant = ProductVariant::query()->withTrashed()->forCompany($company)
                    ->where('product_id', $product->getKey())
                    ->where('sku_normalized', Str::lower($vSpec[1]))->first();
            }

            if (! $variant) {
                $variant = new ProductVariant;
            }

            $variant->forceFill([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'name' => $vSpec[0],
                'sku' => $vSpec[1],
                'sku_normalized' => Str::lower($vSpec[1]),
                'gtin' => $gtins[$vSpec[1]] ?? null,
                'mpn' => $vSpec[2],
                'status' => in_array($spec['status'] ?? ProductStatus::Active, [ProductStatus::Active, ProductStatus::Archived], true)
                    ? ProductVariantStatus::Active
                    : ProductVariantStatus::Draft,
                'sort_order' => $variant->exists ? $variant->sort_order : 0,
                'created_by' => $variant->exists ? $variant->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
                'deleted_at' => null,
            ])->save();

            if (! $product->default_variant_id) {
                $product->forceFill(['default_variant_id' => $variant->getKey()])->save();
            }

            foreach ($spec['extra'] as $ev) {
                $exists = ProductVariant::query()->forCompany($company)
                    ->where('product_id', $product->getKey())
                    ->where('sku_normalized', Str::lower($ev[1]))->first();

                if (! $exists) {
                    $exists = ProductVariant::query()->withTrashed()->forCompany($company)
                        ->where('product_id', $product->getKey())
                        ->where('sku_normalized', Str::lower($ev[1]))->first();
                }

                if (! $exists) {
                    ProductVariant::create([
                        'company_id' => $company->getKey(),
                        'product_id' => $product->getKey(),
                        'name' => $ev[0], 'sku' => $ev[1],
                        'sku_normalized' => Str::lower($ev[1]),
                        'gtin' => $gtins[$ev[1]] ?? null,
                        'mpn' => $ev[2],
                        'status' => in_array($spec['status'] ?? ProductStatus::Active, [ProductStatus::Active, ProductStatus::Archived], true)
                            ? ProductVariantStatus::Active
                            : ProductVariantStatus::Draft,
                        'sort_order' => 10,
                        'created_by' => $owner->getKey(),
                        'updated_by' => $owner->getKey(),
                    ]);
                } else {
                    $exists->forceFill([
                        'name' => $ev[0],
                        'sku' => $ev[1],
                        'sku_normalized' => Str::lower($ev[1]),
                        'gtin' => $gtins[$ev[1]] ?? $exists->gtin,
                        'mpn' => $ev[2],
                        'status' => in_array($spec['status'] ?? ProductStatus::Active, [ProductStatus::Active, ProductStatus::Archived], true)
                            ? ProductVariantStatus::Active
                            : ProductVariantStatus::Draft,
                        'updated_by' => $owner->getKey(),
                        'deleted_at' => null,
                    ])->save();
                }
            }

            $primaryCat = $categories[$spec['primary']];
            $allCats = array_merge([$primaryCat], array_map(fn ($s) => $categories[$s], $spec['additional']));

            DB::table('category_product')->where('product_id', $product->getKey())->delete();

            foreach ($allCats as $cat) {
                DB::table('category_product')->insert([
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'category_id' => $cat->getKey(),
                    'created_at' => now(),
                ]);
            }

            $product->forceFill(['primary_category_id' => $primaryCat->getKey()])->save();
            $products[$slug] = $product->fresh();
        }

        return $products;
    }

    /** @return array<string, string> */
    private function demoGtins(): array
    {
        return [
            'DEMO-LAMP-40W' => '7350012345600',
            'DEMO-LAMP-40UK' => '7350012345617',
            'DEMO-LAMP-60W' => '7350012345624',
            'DEMO-FE-6KG' => '7350012345631',
            'DEMO-VEST-YL-L' => '7350012345648',
            'DEMO-VEST-YL-M' => '7350012345655',
            'DEMO-GLOVE-M' => '7350012345662',
            'DEMO-GLOVE-L' => '7350012345679',
            'DEMO-DRILL-18V' => '7350012345686',
            'DEMO-CASE-STD' => '7350012345693',
        ];
    }

    private function seedAttributeValues(Company $company, array $products): void
    {
        $productValues = [
            'industrial-led-work-lamp' => [
                'power' => '40W',
                'voltage' => '220-240 V AC',
                'ingress_protection' => 'ip65',
                'light_output' => '4 800 lm',
                'weight' => '2.8 kg',
                'material' => 'Aluminium housing, polycarbonate lens, copper wiring',
                'warranty_period' => '3 years',
            ],
            'fire-extinguisher-6kg' => [
                'weight' => '6 kg',
                'fire_rating' => 'ABC',
                'material' => 'Steel cylinder with dry powder extinguishing agent',
                'warranty_period' => '5 years',
            ],
            'reflective-safety-vest' => [
                'colour' => 'yellow',
                'material' => 'Polyester fabric with reflective tape',
                'weight' => '0.24 kg',
            ],
            'progrip-work-gloves' => [
                'material' => 'Nitrile coated polyester',
                'colour' => 'black',
                'weight' => '0.12 kg',
            ],
            'cordless-drill-18v' => [
                'battery_voltage' => '18 V',
                'repairability' => 'Battery and chuck are replaceable',
                'warranty_period' => '3 years',
                'weight' => '1.7 kg',
            ],
            'industrial-storage-case' => [
                'material' => 'Impact-resistant polypropylene',
                'weight' => '2.1 kg',
            ],
        ];

        foreach ($productValues as $slug => $values) {
            $product = $products[$slug] ?? null;

            if (! $product instanceof Product) {
                continue;
            }

            foreach ($values as $code => $value) {
                $this->setProductAttribute($company, $product, $code, $value);
            }
        }

        foreach ([
            'DEMO-LAMP-40W' => ['size' => null],
            'DEMO-LAMP-40UK' => ['size' => null],
            'DEMO-LAMP-60W' => ['size' => null],
            'DEMO-VEST-YL-L' => ['size' => 'l'],
            'DEMO-VEST-YL-M' => ['size' => 'm'],
            'DEMO-GLOVE-M' => ['size' => 'm'],
            'DEMO-GLOVE-L' => ['size' => 'l'],
        ] as $sku => $values) {
            $variant = ProductVariant::query()
                ->forCompany($company)
                ->where('sku_normalized', Str::lower($sku))
                ->first();

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            foreach ($values as $code => $value) {
                if ($value !== null) {
                    $this->setVariantAttribute($company, $variant, $code, $value);
                }
            }
        }
    }

    private function setProductAttribute(Company $company, Product $product, string $code, string $value): void
    {
        $definition = AttributeDefinition::query()->forCompany($company)->where('code', $code)->first();

        if (! $definition instanceof AttributeDefinition) {
            return;
        }

        $option = $definition->type === AttributeDataType::Select
            ? AttributeOption::query()
                ->forCompany($company)
                ->where('attribute_definition_id', $definition->getKey())
                ->where('code', $value)
                ->first()
            : null;

        $attributeValue = ProductAttributeValue::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->first() ?? new ProductAttributeValue;

        $attributeValue->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'attribute_definition_id' => $definition->getKey(),
            'value_text' => $option instanceof AttributeOption ? null : $value,
            'value_integer' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_option_id' => $option?->getKey(),
        ])->save();
    }

    private function setVariantAttribute(Company $company, ProductVariant $variant, string $code, string $value): void
    {
        $definition = AttributeDefinition::query()->forCompany($company)->where('code', $code)->first();

        if (! $definition instanceof AttributeDefinition) {
            return;
        }

        $option = AttributeOption::query()
            ->forCompany($company)
            ->where('attribute_definition_id', $definition->getKey())
            ->where('code', $value)
            ->first();

        if (! $option instanceof AttributeOption) {
            return;
        }

        $attributeValue = VariantAttributeValue::query()
            ->forCompany($company)
            ->where('product_variant_id', $variant->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->first() ?? new VariantAttributeValue;

        $attributeValue->forceFill([
            'company_id' => $company->getKey(),
            'product_variant_id' => $variant->getKey(),
            'attribute_definition_id' => $definition->getKey(),
            'value_text' => null,
            'value_integer' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_option_id' => $option->getKey(),
        ])->save();
    }

    private function seedProductMedia(Company $company, User $owner, array $products): void
    {
        $provider = new DemoAssetProvider;
        $specs = [
            'industrial-led-work-lamp' => [
                ['lamp-primary', 'industrial-led-work-lamp-front.png', true, 'Main product photo'],
                ['lamp-gallery-1', 'industrial-led-work-lamp-detail.png', false, 'Cable and housing detail'],
                ['lamp-gallery-2', 'industrial-led-work-lamp-packaging.png', false, 'Packaging view'],
            ],
            'fire-extinguisher-6kg' => [
                ['extinguisher-primary', 'fire-extinguisher-front.png', true, 'Main product photo'],
                ['extinguisher-gallery', 'fire-extinguisher-label.png', false, 'Label detail'],
            ],
            'reflective-safety-vest' => [
                ['vest-primary', 'reflective-safety-vest-front.png', true, 'Main product photo'],
            ],
            'progrip-work-gloves' => [
                ['gloves-primary', 'progrip-work-gloves-front.png', true, 'Main product photo'],
            ],
            'cordless-drill-18v' => [
                ['drill-primary', 'cordless-drill-front.png', true, 'Main product photo'],
            ],
            'industrial-storage-case' => [
                ['lamp-gallery-2', 'industrial-storage-case-front.png', true, 'Main product photo'],
            ],
        ];

        foreach ($specs as $slug => $items) {
            $product = $products[$slug] ?? null;

            if (! $product instanceof Product) {
                continue;
            }

            foreach ($items as $position => [$assetKey, $filename, $primary, $altText]) {
                $bytes = $provider->imageBinary($assetKey);
                $image = getimagesizefromstring($bytes);

                if (! is_array($image)) {
                    continue;
                }

                $media = ProductMedia::query()
                    ->forCompany($company)
                    ->where('product_id', $product->getKey())
                    ->whereNull('product_variant_id')
                    ->where('original_filename', $filename)
                    ->first() ?? new ProductMedia;

                $uuid = $media->exists ? $media->uuid : (string) Str::uuid();
                $path = $company->uuid.'/products/'.$product->uuid.'/'.$uuid.'.'.$provider->imageExtension();

                Storage::disk((string) config('catalog.media.disk'))->put($path, $bytes, ['visibility' => 'private']);

                $media->forceFill([
                    'uuid' => $uuid,
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'product_variant_id' => null,
                    'original_filename' => $filename,
                    'storage_path' => $path,
                    'mime_type' => $provider->imageMimeType(),
                    'size_bytes' => strlen($bytes),
                    'width' => (int) $image[0],
                    'height' => (int) $image[1],
                    'checksum_sha256' => hash('sha256', $bytes),
                    'alt_text' => $altText,
                    'caption' => null,
                    'sort_order' => ($position + 1) * 10,
                    'uploaded_by' => $owner->getKey(),
                ])->save();

                if ($primary) {
                    $product->forceFill([
                        'primary_media_id' => $media->getKey(),
                        'updated_by' => $owner->getKey(),
                    ])->save();
                }
            }
        }
    }

    /**
     * @return array<string, list<array{document_uuid: string, document_version_uuid: string, role: string, display_order: int}>>
     */
    private function seedProductDocuments(Company $company, User $owner, array $products): array
    {
        $specs = [
            'industrial-led-work-lamp' => [
                ['doc-conformity-v2', ProductDocumentType::DeclarationOfConformity, 'Declaration of Conformity — Industrial LED Work Lamp', 'Compliance declaration for the LED work lamp.', 'NordiPass Demo Manufacturing AB', 'declaration_of_conformity'],
                ['doc-tech-spec', ProductDocumentType::TechnicalDataSheet, 'Technical Data Sheet — Industrial LED Work Lamp', 'Technical specification and performance characteristics.', 'NordiLight', 'technical_data_sheet'],
                ['doc-user-manual', ProductDocumentType::Instruction, 'User Manual — Industrial LED Work Lamp', 'Operating, care and maintenance instructions.', 'NordiLight', 'instruction'],
                ['doc-warranty', ProductDocumentType::Warranty, 'Warranty — Industrial LED Work Lamp', 'Warranty terms for the product.', 'NordiPass Demo Manufacturing AB', 'warranty'],
                ['doc-recycling', ProductDocumentType::RecyclingGuide, 'Recycling Guide — Industrial LED Work Lamp', 'End-of-life recycling guide.', 'NordiPass Demo Manufacturing AB', 'recycling_guide'],
            ],
            'fire-extinguisher-6kg' => [
                ['doc-conformity', ProductDocumentType::DeclarationOfConformity, 'Declaration of Conformity — Fire Extinguisher 6 kg', 'Compliance declaration for the fire extinguisher.', 'SafeGuard AB', 'declaration_of_conformity'],
                ['doc-user-manual', ProductDocumentType::Instruction, 'Inspection Manual — Fire Extinguisher 6 kg', 'Inspection and service instructions.', 'SafeGuard AB', 'instruction'],
                ['doc-tech-spec', ProductDocumentType::SafetyDataSheet, 'Safety Data Sheet — Dry Powder Agent', 'Safety data sheet for the extinguishing agent.', 'SafeGuard AB', 'safety_data_sheet'],
            ],
            'reflective-safety-vest' => [
                ['doc-conformity', ProductDocumentType::DeclarationOfConformity, 'Declaration of Conformity — Reflective Safety Vest', 'Compliance declaration for PPE garment.', 'NordiSafe', 'declaration_of_conformity'],
                ['doc-user-manual', ProductDocumentType::Instruction, 'Care Instructions — Reflective Safety Vest', 'Use and care instructions.', 'NordiSafe', 'instruction'],
            ],
            'progrip-work-gloves' => [
                ['doc-conformity', ProductDocumentType::DeclarationOfConformity, 'Declaration of Conformity — ProGrip Gloves', 'Compliance declaration for protective gloves.', 'NordiSafe', 'declaration_of_conformity'],
                ['doc-user-manual', ProductDocumentType::Instruction, 'User Manual — ProGrip Gloves', 'Use and inspection instructions.', 'NordiSafe', 'instruction'],
            ],
            'cordless-drill-18v' => [
                ['doc-conformity', ProductDocumentType::DeclarationOfConformity, 'Declaration of Conformity — Cordless Drill 18 V', 'Compliance declaration for cordless drill.', 'NordiTool', 'declaration_of_conformity'],
                ['doc-warranty', ProductDocumentType::Warranty, 'Warranty — Cordless Drill 18 V', 'Warranty terms for the cordless drill.', 'NordiTool', 'warranty'],
            ],
            'industrial-storage-case' => [
                ['doc-tech-spec', ProductDocumentType::TechnicalDataSheet, 'Technical Data Sheet — Industrial Storage Case', 'Technical specification for the storage case.', 'NordiTool', 'technical_data_sheet'],
            ],
        ];

        $references = [];

        foreach ($specs as $slug => $documents) {
            $product = $products[$slug] ?? null;

            if (! $product instanceof Product) {
                continue;
            }

            foreach ($documents as $index => [$assetKey, $type, $title, $description, $issuer, $role]) {
                $document = $this->seedDocument($company, $owner, $product, $assetKey, $type, $title, $description, $issuer);
                $version = $document->currentVersion;

                if (! $version instanceof ProductDocumentVersion) {
                    continue;
                }

                $references[$slug][] = [
                    'document_uuid' => $document->uuid,
                    'document_version_uuid' => $version->uuid,
                    'role' => $role,
                    'display_order' => ($index + 1) * 10,
                ];
            }
        }

        return $references;
    }

    private function seedDocument(
        Company $company,
        User $owner,
        Product $product,
        string $assetKey,
        ProductDocumentType $type,
        string $title,
        string $description,
        string $issuer,
    ): ProductDocument {
        $document = ProductDocument::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->whereHas('currentVersion', fn ($query) => $query->where('title', $title))
            ->with('currentVersion')
            ->first() ?? new ProductDocument;

        $document->forceFill([
            'uuid' => $document->exists ? $document->uuid : (string) Str::uuid(),
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductDocumentStatus::Active,
            'created_by_user_id' => $document->exists ? $document->created_by_user_id : $owner->getKey(),
            'updated_by_user_id' => $owner->getKey(),
            'archived_at' => null,
        ])->save();

        if ($document->currentVersion instanceof ProductDocumentVersion) {
            return $document;
        }

        $provider = new DemoAssetProvider;
        $bytes = $provider->pdfBinary($assetKey);
        $filename = Str::slug($title).'.'.$provider->pdfExtension();
        $storageKey = $company->uuid.'/products/'.$product->uuid.'/documents/'.$document->uuid.'/v1-'.$filename;

        Storage::disk((string) config('documents.disk'))->put($storageKey, $bytes, ['visibility' => 'private']);

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'document_type' => $type,
            'title' => $title,
            'description' => $description,
            'language' => 'en',
            'visibility' => ProductDocumentVisibility::PassportPublic,
            'issuer_name' => $issuer,
            'issue_date' => now()->subMonths(3)->toDateString(),
            'expires_at' => now()->addYears(3)->toDateString(),
            'original_filename' => $filename,
            'mime_type' => $provider->pdfMimeType(),
            'file_extension' => $provider->pdfExtension(),
            'size_bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
            'storage_key' => $storageKey,
            'created_by_user_id' => $owner->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        return $document->fresh('currentVersion');
    }

    /**
     * @param  array<string, list<array{document_uuid: string, document_version_uuid: string, role: string, display_order: int}>>  $documentReferences
     */
    private function seedPassports(Company $company, User $owner, array $products, array $documentReferences): void
    {
        // Product A: Published V2 with all sections filled
        $this->seedLampV2($company, $owner, $products['industrial-led-work-lamp'], $documentReferences['industrial-led-work-lamp'] ?? []);

        // Product B: Published V1 with warnings (some sections missing)
        $this->seedExtinguisherV1($company, $owner, $products['fire-extinguisher-6kg'], $documentReferences['fire-extinguisher-6kg'] ?? []);

        // Product C: Draft not ready (only identity)
        $this->seedVestDraft($company, $owner, $products['reflective-safety-vest'], $documentReferences['reflective-safety-vest'] ?? []);

        // Product D: Unpublished (was published, now withdrawn)
        $this->seedGlovesUnpublished($company, $owner, $products['progrip-work-gloves'], $documentReferences['progrip-work-gloves'] ?? []);

        // Product E: Archived
        $this->seedDrillArchived($company, $owner, $products['cordless-drill-18v'], $documentReferences['cordless-drill-18v'] ?? []);

        // Product F: No Passport (storage case — intentionally not created)

        // Apply multilingual language configuration
        $this->applyMultilingualDefaults($company, $products);
    }

    private function applyMultilingualDefaults(Company $company, array $products): void
    {
        // Product A: English default, Swedish enabled
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $products['industrial-led-work-lamp']->getKey())->first();
        if ($passport) {
            $passport->forceFill([
                'default_language' => 'en',
                'enabled_languages' => ['en', 'sv'],
            ])->save();
        }

        // Product B: English default, Swedish enabled (partial)
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $products['fire-extinguisher-6kg']->getKey())->first();
        if ($passport) {
            $passport->forceFill([
                'default_language' => 'en',
                'enabled_languages' => ['en', 'sv'],
            ])->save();
        }

        // Product C: English default only
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $products['reflective-safety-vest']->getKey())->first();
        if ($passport) {
            $passport->forceFill([
                'default_language' => 'en',
                'enabled_languages' => ['en'],
            ])->save();
        }
    }

    /**
     * @param  list<array{document_uuid: string, document_version_uuid?: string, role: string, display_order: int}>  $documentReferences
     */
    private function makePayload(array $sectionData, array $documentReferences = []): array
    {
        $registry = app(DppSchemaRegistry::class);
        $data = [];
        $translations = [];
        $aliases = [
            'manufacturer_contact_email' => 'manufacturer_email',
            'responsible_operator' => 'responsible_operator_display_name',
            'support_website' => 'support_url',
            'safety_instructions' => 'warnings',
        ];

        foreach ($sectionData as $field => $value) {
            $field = $aliases[$field] ?? $field;
            $definition = $registry->field($field);

            if ($definition === null) {
                continue;
            }

            if ($definition->type === DppFieldType::StringList && is_string($value)) {
                $value = [$value];
            }

            $sectionKey = $definition->section->value;

            if ($definition->translatable) {
                $translations['en'][$sectionKey][$field] = $value;
                $translations['sv'][$sectionKey][$field] = $value;

                continue;
            }

            $data[$sectionKey][$field] = $value;
        }

        return app(DppPayloadNormalizer::class)->normalize([
            'enabled_sections' => array_map(fn ($s) => $s->value, DppSectionKey::cases()),
            'data' => $data,
            'translations' => $translations,
            'document_references' => $documentReferences,
        ]);
    }

    /**
     * @param  list<array{document_uuid: string, document_version_uuid?: string, role: string, display_order: int}>  $documentReferences
     */
    private function buildPayloadVersion(
        Company $company,
        User $owner,
        ProductPassport $passport,
        int $versionNumber,
        array $sectionData,
        ProductPassportVersionStatus $status,
        bool $isCurrentDraft = false,
        array $documentReferences = [],
    ): ProductPassportVersion {
        $payload = $this->makePayload($sectionData, $documentReferences);
        $profile = app(ReadinessProfileRepository::class)->active();
        $versionUuid = (string) Str::uuid();
        $assetRows = [];

        if ($status === ProductPassportVersionStatus::Published) {
            $snapshot = app(PassportSnapshotBuilder::class)->build($payload, $passport->product);
            [$payload, $assetRows] = $this->prepareImmutableSnapshotAssets($company, $passport, $versionUuid, $snapshot);
        }

        $checksum = $status === ProductPassportVersionStatus::Published
            ? app(CanonicalJsonEncoder::class)->hash($payload)
            : null;

        $version = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->where('version_number', $versionNumber)
            ->first();

        // Do not update existing versions (they might be immutable due to triggers)
        if ($version) {
            return $version;
        }

        $fill = [
            'uuid' => $versionUuid,
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => $status,
            'version_number' => $versionNumber,
            'draft_revision' => $versionNumber,
            'schema_version' => '1',
            'payload' => $payload,
            'readiness_profile' => $profile->code,
            'readiness_profile_version' => $profile->version,
            'readiness_rule_set_fingerprint' => $profile->fingerprint,
            'created_by' => $owner->getKey(),
        ];

        if ($status === ProductPassportVersionStatus::Published) {
            $fill['published_at'] = now();
            $fill['content_checksum'] = $checksum;
        }

        $version = ProductPassportVersion::create($fill);

        foreach ($assetRows as $row) {
            $asset = new ProductPassportAsset;
            $asset->forceFill($row + ['version_id' => $version->getKey()]);
            $asset->save();
        }

        if ($isCurrentDraft) {
            $passport->forceFill([
                'current_draft_version_id' => $version->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();
        }

        return $version;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function prepareImmutableSnapshotAssets(
        Company $company,
        ProductPassport $passport,
        string $versionUuid,
        array $snapshot,
    ): array {
        $rows = [];

        if (isset($snapshot['_catalog_context']['media'])) {
            foreach ($snapshot['_catalog_context']['media'] as &$mediaItem) {
                $sourcePath = $mediaItem['storage_path'] ?? null;

                if (! is_string($sourcePath) || ! Storage::disk('catalog_media')->exists($sourcePath)) {
                    continue;
                }

                $assetUuid = (string) Str::uuid();
                $extension = $mediaItem['file_extension'] ?? 'png';
                $storageKey = $company->uuid.'/'.$passport->uuid.'/versions/'.$versionUuid.'/media/'.$assetUuid.'.'.$extension;
                $bytes = Storage::disk('catalog_media')->get($sourcePath);

                Storage::disk('passport_assets')->put($storageKey, $bytes);

                $rows[] = [
                    'uuid' => $assetUuid,
                    'company_id' => $company->getKey(),
                    'passport_id' => $passport->getKey(),
                    'kind' => ProductPassportAssetKind::ProductMedia,
                    'source_resource_uuid' => $mediaItem['uuid'] ?? null,
                    'role' => 'product_media',
                    'sort_order' => $mediaItem['sort_order'] ?? 0,
                    'language' => null,
                    'mime_type' => $mediaItem['mime_type'] ?? 'application/octet-stream',
                    'file_extension' => $extension,
                    'size_bytes' => strlen($bytes),
                    'width' => $mediaItem['width'] ?? null,
                    'height' => $mediaItem['height'] ?? null,
                    'checksum_sha256' => hash('sha256', $bytes),
                    'storage_key' => $storageKey,
                    'is_public' => true,
                ];

                $mediaItem['asset_uuid'] = $assetUuid;
                unset($mediaItem['storage_path']);
            }
            unset($mediaItem);
        }

        if (isset($snapshot['_catalog_context']['documents'])) {
            foreach ($snapshot['_catalog_context']['documents'] as &$documentItem) {
                $sourceKey = $documentItem['storage_key'] ?? null;

                if (! is_string($sourceKey) || ! Storage::disk('product_documents')->exists($sourceKey)) {
                    continue;
                }

                $assetUuid = (string) Str::uuid();
                $extension = $documentItem['file_extension'] ?? 'pdf';
                $storageKey = $company->uuid.'/'.$passport->uuid.'/versions/'.$versionUuid.'/documents/'.$assetUuid.'.'.$extension;
                $bytes = Storage::disk('product_documents')->get($sourceKey);
                $isPublic = ($documentItem['visibility'] ?? '') === ProductDocumentVisibility::PassportPublic->value;

                Storage::disk('passport_assets')->put($storageKey, $bytes);

                $rows[] = [
                    'uuid' => $assetUuid,
                    'company_id' => $company->getKey(),
                    'passport_id' => $passport->getKey(),
                    'kind' => ProductPassportAssetKind::Document,
                    'source_resource_uuid' => $documentItem['document_uuid'] ?? null,
                    'role' => $documentItem['role'] ?? 'other',
                    'sort_order' => $documentItem['display_order'] ?? 0,
                    'language' => $documentItem['language'] ?? null,
                    'mime_type' => $documentItem['mime_type'] ?? 'application/octet-stream',
                    'file_extension' => $extension,
                    'size_bytes' => strlen($bytes),
                    'width' => null,
                    'height' => null,
                    'checksum_sha256' => hash('sha256', $bytes),
                    'storage_key' => $storageKey,
                    'is_public' => $isPublic,
                ];

                $documentItem['asset_uuid'] = $assetUuid;
                unset($documentItem['storage_key']);
            }
            unset($documentItem);
        }

        return [$snapshot, $rows];
    }

    private function publish(ProductPassport $passport, int $versionNumber, User $owner): void
    {
        $version = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->where('version_number', $versionNumber)
            ->first();

        if (! $version) {
            return;
        }

        // Supersede previous published version
        $prev = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->where('id', '!=', $version->getKey())
            ->where('status', ProductPassportVersionStatus::Published->value)
            ->first();

        if ($prev) {
            DB::statement('UPDATE product_passport_versions SET status = ? WHERE id = ?', [
                ProductPassportVersionStatus::Superseded->value,
                $prev->getKey(),
            ]);
        }

        /** @var Company $company */
        $company = $passport->company;

        $passport->forceFill([
            'current_published_version_id' => $version->getKey(),
            'current_draft_version_id' => $this->ensureDraftFromPublished($company, $passport, $version, $owner)->getKey(),
            'status' => ProductPassportStatus::Published,
            'first_published_at' => $passport->first_published_at ?? now(),
            'last_published_at' => now(),
            'updated_by' => $owner->getKey(),
        ])->save();
    }

    private function ensureDraftFromPublished(
        Company $company,
        ProductPassport $passport,
        ProductPassportVersion $publishedVersion,
        User $owner,
    ): ProductPassportVersion {
        $draft = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->where('status', ProductPassportVersionStatus::Draft->value)
            ->first();

        if ($draft instanceof ProductPassportVersion) {
            $draft->forceFill([
                'payload' => $publishedVersion->payload,
                'draft_revision' => max(1, ($publishedVersion->version_number ?? 1) + 1),
                'schema_version' => $publishedVersion->schema_version,
                'updated_by' => $owner->getKey(),
            ])->save();

            return $draft;
        }

        $draft = new ProductPassportVersion;
        $draft->forceFill([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => ProductPassportVersionStatus::Draft,
            'version_number' => null,
            'draft_revision' => max(1, ($publishedVersion->version_number ?? 1) + 1),
            'schema_version' => $publishedVersion->schema_version,
            'payload' => $publishedVersion->payload,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        return $draft;
    }

    // --- Scenarios ---

    private function seedLampV2(Company $company, User $owner, Product $product, array $documentReferences): void
    {
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $product->getKey())->first();

        if ($passport && $passport->versions()->where('version_number', 2)->where('status', ProductPassportVersionStatus::Published)->exists()) {
            return;
        }

        if (! $passport) {
            $passport = ProductPassport::create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'status' => ProductPassportStatus::Draft,
                'default_language' => config('passports.default_language', 'sv'),
                'enabled_languages' => [config('passports.default_language', 'sv')],
                'created_by' => $owner->getKey(),
            ]);
        }

        $identity = [
            'public_name' => 'NordiLight Industrial LED Work Lamp 40 W',
            'public_description' => 'Portable industrial LED work lamp designed for construction, workshops and temporary work areas.',
            'gtin' => '07345100000019', 'sku' => 'DEMO-LAMP-40W', 'mpn' => 'NL-WORK-40',
            'brand' => 'NordiLight', 'manufacturer' => 'NordiPass Demo Manufacturing AB',
        ];

        $manufacturer = [
            'manufacturer_display_name' => 'NordiPass Demo Manufacturing AB',
            'manufacturer_contact_email' => 'manufacturer@nordipass.test',
            'manufacturer_website' => 'https://example.test/manufacturer',
            'manufacturer_country' => 'SE',
            'responsible_operator' => 'NordiPass Demo AB',
            'responsible_operator_email' => 'compliance@nordipass.test',
            'responsible_operator_website' => 'https://example.test/compliance',
            'responsible_operator_country' => 'SE',
        ];

        $origin = ['country_of_origin' => 'SE', 'manufacturing_countries' => ['SE', 'PL']];

        $materials = [
            'materials' => [
                ['name' => 'Aluminium housing', 'percentage' => 55],
                ['name' => 'Polycarbonate lens', 'percentage' => 20],
                ['name' => 'Copper and electronics', 'percentage' => 15],
                ['name' => 'Other materials', 'percentage' => 10],
            ],
            'recycled_content' => [['name' => 'Recycled aluminium', 'percentage' => 35]],
        ];

        $safety = [
            'safety_instructions' => "Disconnect the lamp from power before cleaning or maintenance.\nDo not use the product if the cable or enclosure is damaged.\nStore in a dry location when not in use.",
            'safety_reviewed' => true,
        ];

        $usage = ['usage_instructions' => "Place the lamp on a stable surface.\nAvoid covering ventilation openings.\nClean with a dry or slightly damp cloth."];

        $repair = ['repairable' => true, 'spare_parts_available' => true];

        $recycling = ['recycling_instructions' => "Do not dispose of the lamp with household waste.\nTake the product to an approved electrical recycling facility."];

        $env = ['environmental_claims' => 'The aluminium housing contains 35% recycled aluminium based on supplier declarations.'];

        $support = [
            'support_email' => 'support@nordipass.test',
            'support_website' => 'https://example.test/support',
            'support_phone' => '+46 26 000 00 00',
        ];

        $allSections = array_merge(
            $identity, $manufacturer, $origin, $materials, $safety, $usage, $repair, $recycling, $env, $support
        );

        // Version 1
        $this->buildPayloadVersion($company, $owner, $passport, 1, $allSections, ProductPassportVersionStatus::Published, false, $documentReferences);
        $this->publish($passport, 1, $owner);

        // Version 2 — different identity and environmental
        $identityV2 = array_merge($identity, [
            'public_name' => 'NordiLight Industrial LED Work Lamp 40 W (Updated)',
            'public_description' => 'Updated portable industrial LED work lamp with improved efficiency.',
        ]);
        $envV2 = ['environmental_claims' => 'The aluminium housing contains 35% recycled aluminium based on supplier declarations. Updated: now also uses 100% recycled packaging.'];

        $v2Sections = array_merge(
            $identityV2, $manufacturer, $origin, $materials, $safety, $usage, $repair, $recycling, $envV2, $support
        );

        $this->buildPayloadVersion($company, $owner, $passport, 2, $v2Sections, ProductPassportVersionStatus::Published, false, $documentReferences);
        $this->publish($passport, 2, $owner);
    }

    private function seedExtinguisherV1(Company $company, User $owner, Product $product, array $documentReferences): void
    {
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $product->getKey())->first();

        if ($passport && $passport->versions()->where('version_number', 1)->where('status', ProductPassportVersionStatus::Published)->exists()) {
            return;
        }

        if (! $passport) {
            $passport = ProductPassport::create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'status' => ProductPassportStatus::Draft,
                'default_language' => config('passports.default_language', 'sv'),
                'enabled_languages' => [config('passports.default_language', 'sv')],
                'created_by' => $owner->getKey(),
            ]);
        }

        $sections = array_merge(
            [
                'public_name' => 'SafeGuard Fire Extinguisher 6 kg',
                'public_description' => 'Six kilogram dry powder fire extinguisher with ABC rating.',
                'gtin' => '07345100000026', 'sku' => 'DEMO-FE-6KG', 'mpn' => 'SG-FE-6KG',
                'brand' => 'SafeGuard', 'manufacturer' => 'SafeGuard AB',
            ],
            [
                'manufacturer_display_name' => 'SafeGuard AB',
                'manufacturer_contact_email' => 'info@safeguard.test',
                'manufacturer_country' => 'SE',
                'responsible_operator' => 'NordiPass Demo AB',
                'responsible_operator_email' => 'compliance@nordipass.test',
                'responsible_operator_country' => 'SE',
            ],
            [
                'materials' => [
                    ['name' => 'Steel cylinder', 'percentage' => 80],
                    ['name' => 'Plastic components', 'percentage' => 15],
                    ['name' => 'Dry powder agent', 'percentage' => 5],
                ],
            ],
            [
                'safety_instructions' => "Check pressure gauge monthly.\nReplace or service every 5 years.\nStore upright in accessible location.",
                'safety_reviewed' => true,
            ],
            [
                'recycling_instructions' => "Discharge fully before recycling.\nTake to authorised fire extinguisher recycling centre.\nDo not dispose of in household waste.",
            ],
        );

        $this->buildPayloadVersion($company, $owner, $passport, 1, $sections, ProductPassportVersionStatus::Published, false, $documentReferences);
        $this->publish($passport, 1, $owner);
    }

    private function seedVestDraft(Company $company, User $owner, Product $product, array $documentReferences): void
    {
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $product->getKey())->first();

        if ($passport) {
            return;
        }

        $passport = ProductPassport::create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductPassportStatus::Draft,
            'default_language' => config('passports.default_language', 'sv'),
            'enabled_languages' => [config('passports.default_language', 'sv')],
            'created_by' => $owner->getKey(),
        ]);

        $this->buildPayloadVersion($company, $owner, $passport, 1, [
            'public_name' => 'Reflective Safety Vest',
            'public_description' => 'High-visibility reflective safety vest.',
            'gtin' => '07345100000033', 'sku' => 'DEMO-VEST-YL-L', 'mpn' => 'NS-VEST-YL-L',
            'brand' => 'NordiSafe',
        ], ProductPassportVersionStatus::Draft, true, $documentReferences);
    }

    private function seedGlovesUnpublished(Company $company, User $owner, Product $product, array $documentReferences): void
    {
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $product->getKey())->first();

        if ($passport && $passport->isUnpublished()) {
            return;
        }

        if (! $passport) {
            $passport = ProductPassport::create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'status' => ProductPassportStatus::Draft,
                'default_language' => config('passports.default_language', 'sv'),
                'enabled_languages' => [config('passports.default_language', 'sv')],
                'created_by' => $owner->getKey(),
            ]);
        }

        $sections = array_merge(
            [
                'public_name' => 'ProGrip Protective Work Gloves',
                'public_description' => 'Durable protective work gloves.',
                'gtin' => '07345100000040', 'sku' => 'DEMO-GLOVE-M', 'mpn' => 'NS-GLOVE-M',
                'brand' => 'NordiSafe', 'manufacturer' => 'NordiPass Demo Manufacturing AB',
            ],
            [
                'safety_instructions' => 'Inspect gloves before each use. Replace if damaged.',
                'safety_reviewed' => true,
            ],
        );

        $this->buildPayloadVersion($company, $owner, $passport, 1, $sections, ProductPassportVersionStatus::Published, false, $documentReferences);
        $this->publish($passport, 1, $owner);

        // Unpublish
        $passport->refresh();
        $passport->forceFill([
            'current_published_version_id' => null,
            'status' => ProductPassportStatus::Unpublished,
            'unpublished_at' => now(),
            'updated_by' => $owner->getKey(),
        ])->save();

        DB::statement('UPDATE product_passport_versions SET status = ? WHERE passport_id = ? AND version_number = 1', [
            ProductPassportVersionStatus::Withdrawn->value,
            $passport->getKey(),
        ]);
    }

    private function seedDrillArchived(Company $company, User $owner, Product $product, array $documentReferences): void
    {
        $passport = ProductPassport::query()->forCompany($company)
            ->where('product_id', $product->getKey())->first();

        if ($passport && $passport->isArchived()) {
            return;
        }

        if (! $passport) {
            $passport = ProductPassport::create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'status' => ProductPassportStatus::Draft,
                'default_language' => config('passports.default_language', 'sv'),
                'enabled_languages' => [config('passports.default_language', 'sv')],
                'created_by' => $owner->getKey(),
            ]);
        }

        $sections = array_merge(
            [
                'public_name' => 'NordiTool Cordless Drill 18 V',
                'public_description' => 'Cordless drill with 18V lithium-ion battery.',
                'gtin' => '07345100000057', 'sku' => 'DEMO-DRILL-18V', 'mpn' => 'NT-DRILL-18V',
                'brand' => 'NordiTool', 'manufacturer' => 'NordiPass Demo Manufacturing AB',
            ],
            [
                'safety_instructions' => 'Wear eye protection. Keep away from water.',
                'safety_reviewed' => true,
            ],
        );

        $this->buildPayloadVersion($company, $owner, $passport, 1, $sections, ProductPassportVersionStatus::Published, false, $documentReferences);
        $this->publish($passport, 1, $owner);

        // Unpublish
        $passport->refresh();
        $passport->forceFill([
            'current_published_version_id' => null,
            'status' => ProductPassportStatus::Unpublished,
            'unpublished_at' => now(),
            'updated_by' => $owner->getKey(),
        ])->save();

        DB::statement('UPDATE product_passport_versions SET status = ? WHERE passport_id = ? AND version_number = 1', [
            ProductPassportVersionStatus::Withdrawn->value,
            $passport->getKey(),
        ]);

        // Archive
        $passport->refresh();
        $passport->forceFill([
            'status' => ProductPassportStatus::Archived,
            'archived_at' => now(),
            'updated_by' => $owner->getKey(),
        ])->save();
    }
}
