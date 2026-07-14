<?php

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
use App\Models\Catalog\CategoryProduct;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductAttributeValueOption;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Catalog\VariantAttributeValueOption;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{Product, ProductVariant} */
function r13ModelAggregate(Company $company, User $actor, string $suffix = 'a'): array
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->id,
        'name' => "Product {$suffix}",
        'slug' => "product-{$suffix}",
        'slug_normalized' => "product-{$suffix}",
        'status' => ProductStatus::Draft,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => "SKU {$suffix}",
        'sku_normalized' => 'SKU'.mb_strtoupper($suffix),
        'status' => ProductVariantStatus::Draft,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    $product->forceFill(['default_variant_id' => $variant->id])->save();

    return [$product->refresh(), $variant->refresh()];
}

function r13ModelCategory(Company $company, User $actor, string $suffix, ?Category $parent = null): Category
{
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'parent_id' => $parent?->id,
        'depth' => $parent === null ? 0 : 1,
        'name' => "Category {$suffix}",
        'slug' => "category-{$suffix}",
        'slug_normalized' => "category-{$suffix}",
        'status' => CategoryStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $category;
}

function r13ModelDefinition(
    Company $company,
    User $actor,
    string $code,
    AttributeDataType $type = AttributeDataType::Select,
): AttributeDefinition {
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id,
        'name' => ucfirst($code),
        'code' => $code,
        'type' => $type,
        'scope' => AttributeScope::Both,
        'required' => false,
        'filterable' => true,
        'searchable' => false,
        'validation_rules' => ['max' => 20],
        'status' => AttributeDefinitionStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $definition;
}

test('catalog models cast schema values and use uuid and soft delete conventions', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    [$product, $variant] = r13ModelAggregate($company, $actor, 'casts');
    $category = r13ModelCategory($company, $actor, 'casts');
    $definition = r13ModelDefinition($company, $actor, 'dimensions', AttributeDataType::Decimal);

    $value = new ProductAttributeValue;
    $value->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'attribute_definition_id' => $definition->id,
        'value_decimal' => '12.3400',
        'value_boolean' => null,
        'value_date' => null,
    ])->save();

    $dateDefinition = r13ModelDefinition($company, $actor, 'released', AttributeDataType::Date);
    $dateValue = new VariantAttributeValue;
    $dateValue->forceFill([
        'company_id' => $company->id,
        'product_variant_id' => $variant->id,
        'attribute_definition_id' => $dateDefinition->id,
        'value_date' => '2026-07-14',
    ])->save();

    expect($product->uuid)->toBeString()->not->toBeEmpty()
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($variant->status)->toBe(ProductVariantStatus::Draft)
        ->and($category->status)->toBe(CategoryStatus::Active)
        ->and($definition->type)->toBe(AttributeDataType::Decimal)
        ->and($definition->scope)->toBe(AttributeScope::Both)
        ->and($definition->filterable)->toBeTrue()
        ->and($definition->searchable)->toBeFalse()
        ->and($definition->validation_rules)->toBe(['max' => 20])
        ->and($value->fresh()->value_decimal)->toBe('12.3400')
        ->and($dateValue->fresh()->value_date)->toBeInstanceOf(CarbonImmutable::class);

    $product->delete();
    $variant->delete();
    $category->delete();

    expect(Product::withTrashed()->find($product->id)?->trashed())->toBeTrue()
        ->and(ProductVariant::withTrashed()->find($variant->id)?->trashed())->toBeTrue()
        ->and(Category::withTrashed()->find($category->id)?->trashed())->toBeTrue();
});

test('catalog relationships map the complete tenant-owned aggregate', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    [$product, $variant] = r13ModelAggregate($company, $actor, 'relations');
    $root = r13ModelCategory($company, $actor, 'root');
    $child = r13ModelCategory($company, $actor, 'child', $root);

    $categoryProduct = new CategoryProduct;
    $categoryProduct->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'category_id' => $child->id,
        'created_at' => now(),
    ])->save();
    $product->forceFill(['primary_category_id' => $child->id])->save();

    $select = r13ModelDefinition($company, $actor, 'colour');
    $option = new AttributeOption;
    $option->forceFill([
        'company_id' => $company->id,
        'attribute_definition_id' => $select->id,
        'label' => 'Blue',
        'code' => 'blue',
        'status' => AttributeOptionStatus::Active,
    ])->save();

    $productValue = new ProductAttributeValue;
    $productValue->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'attribute_definition_id' => $select->id,
        'value_option_id' => $option->id,
    ])->save();

    $variantValue = new VariantAttributeValue;
    $variantValue->forceFill([
        'company_id' => $company->id,
        'product_variant_id' => $variant->id,
        'attribute_definition_id' => $select->id,
        'value_option_id' => $option->id,
    ])->save();

    $multi = r13ModelDefinition($company, $actor, 'features', AttributeDataType::Multiselect);
    $multiOption = new AttributeOption;
    $multiOption->forceFill([
        'company_id' => $company->id,
        'attribute_definition_id' => $multi->id,
        'label' => 'Waterproof',
        'code' => 'waterproof',
        'status' => AttributeOptionStatus::Active,
    ])->save();
    $multiValue = new ProductAttributeValue;
    $multiValue->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'attribute_definition_id' => $multi->id,
    ])->save();
    $assignment = new ProductAttributeValueOption;
    $assignment->forceFill([
        'company_id' => $company->id,
        'attribute_definition_id' => $multi->id,
        'product_attribute_value_id' => $multiValue->id,
        'attribute_option_id' => $multiOption->id,
        'created_at' => now(),
    ])->save();

    $variantMultiValue = new VariantAttributeValue;
    $variantMultiValue->forceFill([
        'company_id' => $company->id,
        'product_variant_id' => $variant->id,
        'attribute_definition_id' => $multi->id,
    ])->save();
    $variantAssignment = new VariantAttributeValueOption;
    $variantAssignment->forceFill([
        'company_id' => $company->id,
        'attribute_definition_id' => $multi->id,
        'variant_attribute_value_id' => $variantMultiValue->id,
        'attribute_option_id' => $multiOption->id,
        'created_at' => now(),
    ])->save();

    $productMedia = new ProductMedia;
    $productMedia->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'original_filename' => 'product.jpg',
        'storage_path' => 'catalog/product.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'width' => 10,
        'height' => 10,
        'checksum_sha256' => str_repeat('a', 64),
        'uploaded_by' => $actor->id,
    ])->save();
    $variantMedia = new ProductMedia;
    $variantMedia->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'original_filename' => 'variant.jpg',
        'storage_path' => 'catalog/variant.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 200,
        'checksum_sha256' => str_repeat('b', 64),
        'uploaded_by' => $actor->id,
    ])->save();
    $product->forceFill(['primary_media_id' => $productMedia->id])->save();
    $variant->forceFill(['primary_media_id' => $variantMedia->id])->save();

    expect($company->products()->sole()->is($product))->toBeTrue()
        ->and($company->categories()->count())->toBe(2)
        ->and($company->productVariants()->sole()->is($variant))->toBeTrue()
        ->and($company->attributeDefinitions()->count())->toBe(2)
        ->and($company->attributeOptions()->count())->toBe(2)
        ->and($company->productMedia()->count())->toBe(2)
        ->and($product->company->is($company))->toBeTrue()
        ->and($product->variants()->sole()->is($variant))->toBeTrue()
        ->and($product->defaultVariant->is($variant))->toBeTrue()
        ->and($product->categories()->sole()->is($child))->toBeTrue()
        ->and($product->primaryCategory->is($child))->toBeTrue()
        ->and($product->productMedia()->sole()->is($productMedia))->toBeTrue()
        ->and($product->media()->count())->toBe(2)
        ->and($product->primaryMedia->is($productMedia))->toBeTrue()
        ->and($variant->product->is($product))->toBeTrue()
        ->and($variant->media()->sole()->is($variantMedia))->toBeTrue()
        ->and($variant->primaryMedia->is($variantMedia))->toBeTrue()
        ->and($root->children()->sole()->is($child))->toBeTrue()
        ->and($child->parent->is($root))->toBeTrue()
        ->and($select->options()->sole()->is($option))->toBeTrue()
        ->and($productValue->definition->is($select))->toBeTrue()
        ->and($productValue->product->is($product))->toBeTrue()
        ->and($productValue->selectedOption->is($option))->toBeTrue()
        ->and($variantValue->variant->is($variant))->toBeTrue()
        ->and($variantValue->selectedOption->is($option))->toBeTrue()
        ->and($multiValue->selectedOptions()->sole()->is($multiOption))->toBeTrue()
        ->and($variantMultiValue->selectedOptions()->sole()->is($multiOption))->toBeTrue()
        ->and($assignment->attributeValue->is($multiValue))->toBeTrue()
        ->and($variantAssignment->attributeValue->is($variantMultiValue))->toBeTrue()
        ->and($productMedia->product->is($product))->toBeTrue()
        ->and($variantMedia->variant->is($variant))->toBeTrue()
        ->and($product->createdBy->is($actor))->toBeTrue()
        ->and($variant->updatedBy->is($actor))->toBeTrue();
});

test('explicit company and state scopes never leak another tenant', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    [$productA, $variantA] = r13ModelAggregate($companyA, $actor, 'scope-a');
    [$productB] = r13ModelAggregate($companyB, $actor, 'scope-b');
    $productA->forceFill(['status' => ProductStatus::Active])->save();
    $variantA->forceFill(['status' => ProductVariantStatus::Active])->save();
    $root = r13ModelCategory($companyA, $actor, 'scope-root');
    r13ModelCategory($companyA, $actor, 'scope-child', $root);

    expect(Product::forCompany($companyA)->pluck('id')->all())->toBe([$productA->id])
        ->and(Product::forCompany((string) $companyA->id)->pluck('id')->all())->toBe([$productA->id])
        ->and(Product::forCompany($companyA->uuid)->pluck('id')->all())->toBe([$productA->id])
        ->and(Product::forCompany($companyB)->pluck('id')->all())->toBe([$productB->id])
        ->and(Product::forCompany($companyA)->active()->sole()->is($productA))->toBeTrue()
        ->and(ProductVariant::forCompany($companyA)->active()->sole()->is($variantA))->toBeTrue()
        ->and(Category::forCompany($companyA)->roots()->sole()->is($root))->toBeTrue()
        ->and(Category::forCompany($companyB)->count())->toBe(0);
});

test('mass assignment cannot set tenant owner pointers audit actors or normalized identifiers', function () {
    $product = new Product([
        'company_id' => 99,
        'default_variant_id' => 10,
        'primary_category_id' => 11,
        'primary_media_id' => 12,
        'created_by' => 13,
        'updated_by' => 14,
        'slug_normalized' => 'forged',
        'published_at' => now(),
        'name' => 'Allowed',
    ]);
    $variant = new ProductVariant([
        'company_id' => 99,
        'product_id' => 10,
        'primary_media_id' => 12,
        'created_by' => 13,
        'updated_by' => 14,
        'sku_normalized' => 'FORGED',
        'sku' => 'Allowed',
    ]);
    $media = new ProductMedia([
        'company_id' => 99,
        'product_id' => 10,
        'product_variant_id' => 11,
        'uploaded_by' => 12,
        'storage_path' => 'forged',
        'caption' => 'Allowed',
    ]);
    $value = new ProductAttributeValue([
        'company_id' => 99,
        'product_id' => 10,
        'attribute_definition_id' => 11,
        'value_text' => 'Allowed',
    ]);
    $definition = new AttributeDefinition([
        'company_id' => 99,
        'created_by' => 10,
        'updated_by' => 11,
        'name' => 'Allowed',
    ]);
    $option = new AttributeOption([
        'company_id' => 99,
        'attribute_definition_id' => 10,
        'label' => 'Allowed',
    ]);
    $assignmentPayload = [
        'company_id' => 99,
        'attribute_definition_id' => 10,
        'product_attribute_value_id' => 11,
        'attribute_option_id' => 12,
    ];

    expect($product->getAttributes())->not->toHaveKeys([
        'company_id', 'default_variant_id', 'primary_category_id', 'primary_media_id',
        'created_by', 'updated_by', 'slug_normalized', 'published_at',
    ])->and($product->name)->toBe('Allowed')
        ->and($variant->getAttributes())->not->toHaveKeys([
            'company_id', 'product_id', 'primary_media_id', 'created_by', 'updated_by', 'sku_normalized',
        ])->and($variant->sku)->toBe('Allowed')
        ->and($media->getAttributes())->not->toHaveKeys([
            'company_id', 'product_id', 'product_variant_id', 'uploaded_by', 'storage_path',
        ])->and($media->caption)->toBe('Allowed')
        ->and($value->getAttributes())->not->toHaveKeys([
            'company_id', 'product_id', 'attribute_definition_id',
        ])->and($value->value_text)->toBe('Allowed')
        ->and($definition->getAttributes())->not->toHaveKeys([
            'company_id', 'created_by', 'updated_by',
        ])->and($definition->name)->toBe('Allowed')
        ->and($option->getAttributes())->not->toHaveKeys([
            'company_id', 'attribute_definition_id',
        ])->and($option->label)->toBe('Allowed')
        ->and(fn () => new ProductAttributeValueOption($assignmentPayload))
        ->toThrow(MassAssignmentException::class);
});
