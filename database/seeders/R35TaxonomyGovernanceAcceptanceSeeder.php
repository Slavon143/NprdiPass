<?php

namespace Database\Seeders;

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionApprovalStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\Taxonomy\CategoryTemplateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class R35TaxonomyGovernanceAcceptanceSeeder extends Seeder
{
    public const CATEGORY_UUID = '00000000-0000-4000-8000-000000003501';

    public const PRODUCT_UUID = '00000000-0000-4000-8000-000000003502';

    public const ATTRIBUTE_UUID = '00000000-0000-4000-8000-000000003503';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'acceptance'])) {
            return;
        }

        $this->call(LocalDevelopmentSeeder::class);

        DB::transaction(function (): void {
            $company = Company::query()->where('name', 'NordiPass Demo AB')->firstOrFail();
            $owner = User::query()->where('email', 'owner@nordipass.local')->firstOrFail();
            $category = $this->category($company, $owner);
            $attribute = $this->attribute($company, $owner);
            $product = $this->product($company, $owner, $category);
            $this->options($company, $attribute);

            if ($category->activeTemplate()->doesntExist()) {
                $templates = app(CategoryTemplateService::class);
                $template = $templates->createDraft($category, $owner, 'Apparel governance standard');
                $templates->assignAttribute($template, $attribute, CategoryTemplateAttributeRequirement::Required, 10);
                $templates->setDppDefault($template, 'identity', ['public_name' => 'Category-governed product']);
                $templates->activate($template, $owner);
            }

            $product->forceFill(['taxonomy_needs_review' => true, 'taxonomy_reviewed_at' => null])->save();
        });
    }

    private function category(Company $company, User $owner): Category
    {
        $category = Category::query()->firstOrNew(['uuid' => self::CATEGORY_UUID]);
        $category->forceFill([
            'company_id' => $company->getKey(),
            'depth' => 0,
            'name' => 'R3.5 Apparel Governance',
            'slug' => 'r3-5-apparel-governance',
            'slug_normalized' => 'r3-5-apparel-governance',
            'sort_order' => 350,
            'status' => CategoryStatus::Active,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        return $category->refresh();
    }

    private function attribute(Company $company, User $owner): AttributeDefinition
    {
        $attribute = AttributeDefinition::query()->firstOrNew(['uuid' => self::ATTRIBUTE_UUID]);
        $attribute->forceFill([
            'company_id' => $company->getKey(),
            'name' => 'Primary material',
            'code' => 'primary_material',
            'description' => 'Controlled primary material for R3.5 acceptance.',
            'type' => AttributeDataType::Select,
            'scope' => AttributeScope::Product,
            'required' => false,
            'filterable' => true,
            'searchable' => true,
            'vocabulary_controlled' => true,
            'sort_order' => 350,
            'status' => AttributeDefinitionStatus::Active,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        return $attribute->refresh();
    }

    private function product(Company $company, User $owner, Category $category): Product
    {
        $product = Product::query()->firstOrNew(['uuid' => self::PRODUCT_UUID]);
        $product->forceFill([
            'company_id' => $company->getKey(),
            'primary_category_id' => $category->getKey(),
            'name' => 'R3.5 Taxonomy Review Jacket',
            'slug' => 'r3-5-taxonomy-review-jacket',
            'slug_normalized' => 'r3-5-taxonomy-review-jacket',
            'short_description' => 'Acceptance fixture for category taxonomy review.',
            'brand' => 'NordiPass Lab',
            'manufacturer' => 'NordiPass Demo AB',
            'status' => ProductStatus::Draft,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        $product->categories()->syncWithoutDetaching([
            $category->getKey() => ['company_id' => $company->getKey(), 'created_at' => now()],
        ]);

        return $product->refresh();
    }

    private function options(Company $company, AttributeDefinition $attribute): void
    {
        $this->option($company, $attribute, 'Cotton', 'cotton', AttributeOptionApprovalStatus::Approved, 10);
        $this->option($company, $attribute, 'Regenerated fibre', 'regenerated_fibre', AttributeOptionApprovalStatus::Pending, 20);
    }

    private function option(
        Company $company,
        AttributeDefinition $attribute,
        string $label,
        string $code,
        AttributeOptionApprovalStatus $approval,
        int $sortOrder,
    ): void {
        $option = AttributeOption::query()->firstOrNew([
            'company_id' => $company->getKey(),
            'attribute_definition_id' => $attribute->getKey(),
            'code' => $code,
        ]);
        $option->forceFill([
            'label' => $label,
            'sort_order' => $sortOrder,
            'status' => AttributeOptionStatus::Active,
            'approval_status' => $approval,
            'approved_at' => $approval === AttributeOptionApprovalStatus::Approved ? now() : null,
        ])->save();
    }
}
