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
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Enums\UserStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class NordiPassShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $company = $this->seedCompany();
        $users = $this->seedUsers($company);
        $owner = $users['demo.owner@nordipass.test'];

        DB::transaction(function () use ($company, $owner): void {
            $this->seedAttributes($company, $owner);
            $categories = $this->seedCategories($company, $owner);
            $products = $this->seedProducts($company, $owner, $categories);
            $this->seedPassports($company, $owner, $products);
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

        $password = config('passports.demo_password') ?: bin2hex(random_bytes(16));

        if (! app()->runningUnitTests() && $this->command !== null && empty(config('passports.demo_password'))) {
            $this->command->info("Demo password generated: {$password}");
        }

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
            ],
            'industrial-storage-case' => [
                'name' => 'Industrial Storage Case', 'brand' => 'NordiTool',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'primary' => 'power-tools', 'additional' => ['industrial-equipment'],
                'desc' => 'Heavy-duty industrial storage case for tools and accessories.',
                'variant' => ['Standard', 'DEMO-CASE-STD', 'NT-CASE-STD'], 'extra' => [],
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
                'status' => ProductStatus::Active,
                'published_at' => $product->published_at ?? now(),
                'created_by' => $product->exists ? $product->created_by : $owner->getKey(),
                'updated_by' => $owner->getKey(),
                'deleted_at' => null,
            ])->save();

            $vSpec = $spec['variant'];

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
                'mpn' => $vSpec[2],
                'status' => ProductVariantStatus::Active,
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
                        'sku_normalized' => Str::lower($ev[1]), 'mpn' => $ev[2],
                        'status' => ProductVariantStatus::Active,
                        'sort_order' => 10,
                        'created_by' => $owner->getKey(),
                        'updated_by' => $owner->getKey(),
                    ]);
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

    private function seedPassports(Company $company, User $owner, array $products): void
    {
        // Product A: Published V2 with all sections filled
        $this->seedLampV2($company, $owner, $products['industrial-led-work-lamp']);

        // Product B: Published V1 with warnings (some sections missing)
        $this->seedExtinguisherV1($company, $owner, $products['fire-extinguisher-6kg']);

        // Product C: Draft not ready (only identity)
        $this->seedVestDraft($company, $owner, $products['reflective-safety-vest']);

        // Product D: Unpublished (was published, now withdrawn)
        $this->seedGlovesUnpublished($company, $owner, $products['progrip-work-gloves']);

        // Product E: Archived
        $this->seedDrillArchived($company, $owner, $products['cordless-drill-18v']);

        // Product F: No Passport (storage case — intentionally not created)
    }

    private function makePayload(array $sectionData): array
    {
        return [
            'enabled_sections' => array_map(fn ($s) => $s->value, DppSectionKey::cases()),
            'data' => $sectionData,
            'translations' => [config('passports.default_language', 'sv') => []],
            'document_references' => [],
        ];
    }

    private function buildPayloadVersion(
        Company $company,
        User $owner,
        ProductPassport $passport,
        int $versionNumber,
        array $sectionData,
        ProductPassportVersionStatus $status,
        bool $isCurrentDraft = false,
    ): ProductPassportVersion {
        $payload = $this->makePayload($sectionData);
        $checksum = $status === ProductPassportVersionStatus::Published
            ? hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
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
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => $status,
            'version_number' => $versionNumber,
            'draft_revision' => $versionNumber,
            'schema_version' => '1',
            'payload' => $payload,
            'created_by' => $owner->getKey(),
        ];

        if ($status === ProductPassportVersionStatus::Published) {
            $fill['published_at'] = now();
            $fill['content_checksum'] = $checksum;
        }

        $version = ProductPassportVersion::create($fill);

        if ($isCurrentDraft) {
            $passport->forceFill([
                'current_draft_version_id' => $version->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();
        }

        return $version;
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

        $passport->forceFill([
            'current_published_version_id' => $version->getKey(),
            'current_draft_version_id' => null,
            'status' => ProductPassportStatus::Published,
            'first_published_at' => $passport->first_published_at ?? now(),
            'last_published_at' => now(),
            'updated_by' => $owner->getKey(),
        ])->save();
    }

    // --- Scenarios ---

    private function seedLampV2(Company $company, User $owner, Product $product): void
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
        $this->buildPayloadVersion($company, $owner, $passport, 1, $allSections, ProductPassportVersionStatus::Published);
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

        $this->buildPayloadVersion($company, $owner, $passport, 2, $v2Sections, ProductPassportVersionStatus::Published);
        $this->publish($passport, 2, $owner);
    }

    private function seedExtinguisherV1(Company $company, User $owner, Product $product): void
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

        $this->buildPayloadVersion($company, $owner, $passport, 1, $sections, ProductPassportVersionStatus::Published);
        $this->publish($passport, 1, $owner);
    }

    private function seedVestDraft(Company $company, User $owner, Product $product): void
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
        ], ProductPassportVersionStatus::Draft, true);
    }

    private function seedGlovesUnpublished(Company $company, User $owner, Product $product): void
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

        $this->buildPayloadVersion($company, $owner, $passport, 1, $sections, ProductPassportVersionStatus::Published);
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

    private function seedDrillArchived(Company $company, User $owner, Product $product): void
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

        $this->buildPayloadVersion($company, $owner, $passport, 1, $sections, ProductPassportVersionStatus::Published);
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
