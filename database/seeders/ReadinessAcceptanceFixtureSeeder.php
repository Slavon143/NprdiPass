<?php

namespace Database\Seeders;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\DppSchemaRegistry;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReadinessAcceptanceFixtureSeeder extends Seeder
{
    public const TRAFFIC_SIGNALS_SLUG = 'traffic-signals-acceptance';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call(NordiPassShowcaseSeeder::class);

        DB::transaction(function (): void {
            $company = Company::query()->where('name', 'NordiPass Demo AB')->firstOrFail();
            $owner = User::query()->where('email', 'demo.owner@nordipass.test')->firstOrFail();
            $category = Category::query()
                ->where('company_id', $company->getKey())
                ->where('slug', 'protective-clothing')
                ->firstOrFail();

            $product = Product::query()
                ->where('company_id', $company->getKey())
                ->where('slug_normalized', self::TRAFFIC_SIGNALS_SLUG)
                ->first() ?? new Product;

            $product->forceFill([
                'uuid' => $product->exists ? $product->uuid : (string) Str::uuid(),
                'company_id' => $company->getKey(),
                'name' => 'Traffic signals',
                'slug' => self::TRAFFIC_SIGNALS_SLUG,
                'slug_normalized' => self::TRAFFIC_SIGNALS_SLUG,
                'short_description' => 'Acceptance fixture for traffic-signal readiness diagnosis.',
                'description' => 'Archived traffic-signal fixture with intentionally missing media and default variant.',
                'brand' => 'NordiSignal',
                'manufacturer' => 'NordiPass Demo Manufacturing AB',
                'status' => ProductStatus::Archived,
                'primary_category_id' => $category->getKey(),
                'default_variant_id' => null,
                'primary_media_id' => null,
                'created_by' => $owner->getKey(),
                'updated_by' => $owner->getKey(),
                'created_at' => $product->exists ? $product->created_at : now(),
                'updated_at' => now(),
            ])->save();

            if (! $product->categories()->whereKey($category->getKey())->exists()) {
                $product->categories()->attach($category->getKey(), [
                    'company_id' => $company->getKey(),
                    'created_at' => now(),
                ]);
            }

            $this->removeFixtureVariantAndMedia($product);
            $passport = $this->passport($company, $owner, $product);
            $draft = $this->draft($company, $owner, $passport);

            $passport->forceFill([
                'current_draft_version_id' => $draft->getKey(),
                'updated_by' => $owner->getKey(),
            ])->save();
        });
    }

    private function removeFixtureVariantAndMedia(Product $product): void
    {
        $product->forceFill([
            'default_variant_id' => null,
            'primary_media_id' => null,
        ])->save();

        $product->media()->delete();
        $product->variants()->delete();
    }

    private function passport(Company $company, User $owner, Product $product): ProductPassport
    {
        $passport = ProductPassport::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->first() ?? new ProductPassport;

        $passport->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductPassportStatus::Draft,
            'default_language' => 'en',
            'enabled_languages' => ['en'],
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        return $passport;
    }

    private function draft(Company $company, User $owner, ProductPassport $passport): ProductPassportVersion
    {
        $profile = app(ReadinessProfileRepository::class)->active();
        $draft = $passport->currentDraftVersion ?? new ProductPassportVersion;

        $draft->forceFill([
            'uuid' => $draft->exists ? $draft->uuid : (string) Str::uuid(),
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => ProductPassportVersionStatus::Draft,
            'version_number' => null,
            'draft_revision' => 1,
            'schema_version' => '1',
            'payload' => $this->makePayload([
                'public_name' => 'Traffic signals',
                'public_description' => 'Temporary traffic signal system.',
                'gtin' => '07345100000999',
                'sku' => 'TRAFFIC-001',
                'mpn' => 'NS-TRAFFIC-001',
                'brand' => 'NordiSignal',
            ]),
            'readiness_profile' => $profile->code,
            'readiness_profile_version' => $profile->version,
            'readiness_rule_set_fingerprint' => $profile->fingerprint,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $sectionData
     * @return array<string, mixed>
     */
    private function makePayload(array $sectionData): array
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
            'enabled_sections' => array_map(fn ($section) => $section->value, DppSectionKey::cases()),
            'data' => $data,
            'translations' => $translations,
            'document_references' => [],
        ]);
    }
}
