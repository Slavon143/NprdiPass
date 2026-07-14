<?php

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeScope;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\LocalDevelopmentSeeder;

test('demo attributes options and assignments are typed tenant safe and idempotent', function () {
    $this->seed(LocalDevelopmentSeeder::class);
    $this->seed(CatalogDemoSeeder::class);
    $company = Company::query()->where('name', 'NordiPass Demo AB')->sole();
    $definitions = AttributeDefinition::query()->forCompany($company)->with('options')->get()->keyBy('code');
    $definitionIds = $definitions->pluck('id')->sort()->values()->all();

    expect($definitions)->toHaveCount(6)
        ->and($definitions['size']->type)->toBe(AttributeDataType::Select)
        ->and($definitions['size']->scope)->toBe(AttributeScope::Variant)
        ->and($definitions['size']->required)->toBeTrue()
        ->and($definitions['size']->options->pluck('code')->all())->toBe(['s', 'm', 'l', 'xl'])
        ->and($definitions['material']->scope)->toBe(AttributeScope::Product)
        ->and($definitions['weight']->unit)->toBe('kg')
        ->and($definitions['power']->unit)->toBe('W')
        ->and($definitions['certifications']->type)->toBe(AttributeDataType::Multiselect);

    $gloves = Product::query()->forCompany($company)->where('slug_normalized', 'progrip-work-gloves')->sole();
    $gloveMaterial = ProductAttributeValue::query()->where('product_id', $gloves->id)->where('attribute_definition_id', $definitions['material']->id)->with('selectedOption')->sole();
    $gloveCertifications = ProductAttributeValue::query()->where('product_id', $gloves->id)->where('attribute_definition_id', $definitions['certifications']->id)->with('selectedOptions')->sole();
    expect($gloveMaterial->selectedOption?->code)->toBe('nitrile')
        ->and($gloveCertifications->selectedOptions->pluck('code')->sort()->values()->all())->toBe(['ce', 'en_388']);

    $lamp60 = ProductVariant::query()->forCompany($company)->where('sku_normalized', 'DEMO-LAMP-60W')->sole();
    $lampPower = VariantAttributeValue::query()->where('product_variant_id', $lamp60->id)->where('attribute_definition_id', $definitions['power']->id)->sole();
    expect($lampPower->value_integer)->toBe(60)
        ->and($lampPower->value_text)->toBeNull()
        ->and($lampPower->value_option_id)->toBeNull();

    $productValueIds = ProductAttributeValue::query()->forCompany($company)->orderBy('id')->pluck('id')->all();
    $variantValueIds = VariantAttributeValue::query()->forCompany($company)->orderBy('id')->pluck('id')->all();
    $this->seed(CatalogDemoSeeder::class);
    expect(AttributeDefinition::query()->forCompany($company)->pluck('id')->sort()->values()->all())->toBe($definitionIds)
        ->and(ProductAttributeValue::query()->forCompany($company)->orderBy('id')->pluck('id')->all())->toBe($productValueIds)
        ->and(VariantAttributeValue::query()->forCompany($company)->orderBy('id')->pluck('id')->all())->toBe($variantValueIds);
});
