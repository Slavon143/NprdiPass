<?php

namespace Tests\Feature\Catalog\Taxonomy;

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionApprovalStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Enums\Catalog\CategoryTemplateStatus;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Catalog\Taxonomy\CategoryTemplateService;
use App\Services\Catalog\Taxonomy\ProductReadinessProfileResolver;
use App\Services\Catalog\Taxonomy\ProductTaxonomyDiagnosticsService;
use App\Services\Catalog\Taxonomy\TaxonomyLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CategoryTemplateFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_activation_is_versioned_immutable_and_marks_products_for_review(): void
    {
        [$company, $actor, $category, $product] = $this->catalogContext('versioned');
        $definition = $this->definition($company, $actor, 'material');
        $service = app(CategoryTemplateService::class);

        $v1 = $service->createDraft($category, $actor, 'Textiles v1', 'nordipass-pilot', 1);
        $service->assignAttribute($v1, $definition, CategoryTemplateAttributeRequirement::Required);
        $service->setDppDefault($v1, 'identity', ['public_name' => 'Template product']);
        $v1 = $service->activate($v1, $actor);

        $this->assertSame(CategoryTemplateStatus::Active, $v1->status);
        $this->assertSame($v1->getKey(), $category->fresh()->active_template_id);
        $this->assertTrue($product->fresh()->taxonomy_needs_review);
        $this->assertDatabaseCount('product_attribute_values', 0);
        $this->assertDatabaseCount('product_passport_versions', 0);

        $v2 = $service->createDraft($category, $actor, 'Textiles v2');
        $service->assignAttribute($v2, $definition, CategoryTemplateAttributeRequirement::Recommended);
        $v2 = $service->activate($v2, $actor);

        $this->assertSame(2, $v2->version);
        $this->assertSame(CategoryTemplateStatus::Retired, $v1->fresh()->status);
        $this->assertSame(CategoryTemplateStatus::Active, $v2->status);

        $this->expectException(QueryException::class);
        DB::table('category_template_attributes')->insert([
            'company_id' => $company->getKey(),
            'category_template_id' => $v2->getKey(),
            'attribute_definition_id' => $definition->getKey(),
            'requirement' => CategoryTemplateAttributeRequirement::Required,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_template_assignment_rejects_cross_tenant_attributes_at_domain_and_database_layers(): void
    {
        [$company, $actor, $category] = $this->catalogContext('tenant-a');
        [$otherCompany, $otherActor] = $this->catalogContext('tenant-b');
        $otherDefinition = $this->definition($otherCompany, $otherActor, 'foreign');
        $service = app(CategoryTemplateService::class);
        $template = $service->createDraft($category, $actor, 'Tenant safe');

        try {
            $service->assignAttribute($template, $otherDefinition, CategoryTemplateAttributeRequirement::Required);
            $this->fail('Cross-tenant domain assignment was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('category_template_attributes', 0);
        }

        $this->expectException(QueryException::class);
        DB::table('category_template_attributes')->insert([
            'company_id' => $company->getKey(),
            'category_template_id' => $template->getKey(),
            'attribute_definition_id' => $otherDefinition->getKey(),
            'requirement' => CategoryTemplateAttributeRequirement::Required,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_lifecycle_replacements_are_safe_and_referenced_options_cannot_be_deleted(): void
    {
        [$company, $actor, , $product] = $this->catalogContext('lifecycle');
        $oldDefinition = $this->definition($company, $actor, 'old_colour', AttributeDataType::Select);
        $newDefinition = $this->definition($company, $actor, 'new_colour', AttributeDataType::Select);
        $oldOption = $this->option($oldDefinition, 'old');
        $newOption = $this->option($oldDefinition, 'new');
        $lifecycle = app(TaxonomyLifecycleService::class);

        $oldDefinition = $lifecycle->deprecateDefinition($oldDefinition, $newDefinition);
        $oldOption = $lifecycle->deprecateOption($oldOption, $newOption);

        $this->assertSame(AttributeDefinitionStatus::Deprecated, $oldDefinition->status);
        $this->assertTrue($oldDefinition->replacement->is($newDefinition));
        $this->assertSame(AttributeOptionStatus::Deprecated, $oldOption->status);
        $this->assertTrue($oldOption->replacement->is($newOption));

        $value = new ProductAttributeValue;
        $value->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'attribute_definition_id' => $oldDefinition->getKey(),
            'value_option_id' => $oldOption->getKey(),
        ])->save();

        $this->expectException(ValidationException::class);
        $lifecycle->deleteOption($oldOption);
    }

    public function test_profile_resolution_uses_override_then_category_template_then_active_default(): void
    {
        [, $actor, $category, $product] = $this->catalogContext('profiles');
        $templates = app(CategoryTemplateService::class);
        $resolver = app(ProductReadinessProfileResolver::class);

        $this->assertSame(2, $resolver->resolve($product)->version);

        $template = $templates->createDraft($category, $actor, 'Pinned v1', 'nordipass-pilot', 1);
        $templates->activate($template, $actor);
        $this->assertSame(1, $resolver->resolve($product->fresh())->version);

        $product = $templates->setProductProfileOverride($product->fresh(), 'nordipass-pilot', 2);
        $this->assertSame(2, $resolver->resolve($product)->version);

        $product = $templates->setProductProfileOverride($product, null, null);
        $this->assertSame(1, $resolver->resolve($product)->version);
    }

    public function test_required_attribute_diagnostics_do_not_invent_values(): void
    {
        [$company, $actor, $category, $product] = $this->catalogContext('diagnostics');
        $required = $this->definition($company, $actor, 'required_text');
        $recommended = $this->definition($company, $actor, 'recommended_text');
        $templates = app(CategoryTemplateService::class);
        $diagnostics = app(ProductTaxonomyDiagnosticsService::class);
        $template = $templates->createDraft($category, $actor, 'Diagnostics');
        $templates->assignAttribute($template, $required, CategoryTemplateAttributeRequirement::Required);
        $templates->assignAttribute($template, $recommended, CategoryTemplateAttributeRequirement::Recommended);
        $templates->activate($template, $actor);

        $result = $diagnostics->diagnose($product->fresh());
        $this->assertSame([$required->getKey()], array_map(fn ($item) => $item->getKey(), $result['missing_required']));
        $this->assertSame([$recommended->getKey()], array_map(fn ($item) => $item->getKey(), $result['missing_recommended']));
        $this->assertDatabaseCount('product_attribute_values', 0);

        $value = new ProductAttributeValue;
        $value->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'attribute_definition_id' => $required->getKey(),
            'value_text' => 'Provided by user',
        ])->save();
        $templates->acknowledgeProduct($product->fresh());
        $result = $diagnostics->diagnose($product->fresh());

        $this->assertFalse($result['needs_review']);
        $this->assertSame([], $result['missing_required']);
        $this->assertCount(1, $result['missing_recommended']);
    }

    public function test_legacy_rows_receive_safe_defaults_and_published_passport_payload_is_unchanged(): void
    {
        [$company, $actor, $category, $product] = $this->catalogContext('compatibility');
        $definition = $this->definition($company, $actor, 'legacy_select', AttributeDataType::Select);
        $option = $this->option($definition, 'legacy');
        $passport = ProductPassport::factory()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
        ]);
        $published = ProductPassportVersion::factory()->published()->create([
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'payload' => ['immutable' => ['value' => 'before-r3.5']],
        ]);
        $payload = $published->payload;

        $this->assertFalse($definition->fresh()->vocabulary_controlled);
        $this->assertSame(AttributeOptionApprovalStatus::Approved, $option->fresh()->approval_status);
        $this->assertFalse($product->fresh()->taxonomy_needs_review);
        $this->assertNull($category->fresh()->active_template_id);

        $template = app(CategoryTemplateService::class)->createDraft($category, $actor, 'Compatibility');
        app(CategoryTemplateService::class)->activate($template, $actor);

        $this->assertSame($payload, $published->fresh()->payload);
    }

    /** @return array{Company, User, Category, Product} */
    private function catalogContext(string $suffix): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $category = new Category;
        $category->forceFill([
            'company_id' => $company->getKey(),
            'depth' => 0,
            'name' => "Category {$suffix}",
            'slug' => "category-{$suffix}",
            'slug_normalized' => "category-{$suffix}",
            'status' => CategoryStatus::Active,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();
        $product = new Product;
        $product->forceFill([
            'company_id' => $company->getKey(),
            'primary_category_id' => $category->getKey(),
            'name' => "Product {$suffix}",
            'slug' => "product-{$suffix}",
            'slug_normalized' => "product-{$suffix}",
            'status' => ProductStatus::Draft,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        return [$company, $actor, $category, $product];
    }

    private function definition(
        Company $company,
        User $actor,
        string $code,
        AttributeDataType $type = AttributeDataType::Text,
    ): AttributeDefinition {
        $definition = new AttributeDefinition;
        $definition->forceFill([
            'company_id' => $company->getKey(),
            'name' => str_replace('_', ' ', ucfirst($code)),
            'code' => $code,
            'type' => $type,
            'scope' => AttributeScope::Product,
            'required' => false,
            'filterable' => false,
            'searchable' => false,
            'status' => AttributeDefinitionStatus::Active,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        return $definition->refresh();
    }

    private function option(AttributeDefinition $definition, string $code): AttributeOption
    {
        $option = new AttributeOption;
        $option->forceFill([
            'company_id' => $definition->company_id,
            'attribute_definition_id' => $definition->getKey(),
            'label' => ucfirst($code),
            'code' => $code,
            'status' => AttributeOptionStatus::Active,
        ])->save();

        return $option->refresh();
    }
}
