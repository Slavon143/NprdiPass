<?php

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Attributes\SyncVariantAttributeValuesAction;
use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyRole;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Catalog\AttributeValueValidator;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** @return array{User, Company, Product, ProductVariant} */
function r17ValueContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor, 'company_id' => $company, 'role' => $role]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Attribute Product', 'slug' => 'attribute-product', 'short_description' => null,
        'description' => null, 'brand' => null, 'manufacturer' => null,
    ], [
        'name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0,
    ]);

    return [$actor, $company, $product, $product->defaultVariant()->firstOrFail()];
}

/** @param array<string, int|string> $rules */
function r17ValueDefinition(Company $company, User $actor, string $code, AttributeDataType $type, AttributeScope $scope, array $rules = [], bool $required = false): AttributeDefinition
{
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id, 'name' => str($code)->headline()->toString(), 'code' => $code,
        'description' => null, 'type' => $type, 'scope' => $scope, 'unit' => null, 'required' => $required,
        'filterable' => false, 'searchable' => false, 'validation_rules' => $rules === [] ? null : $rules,
        'sort_order' => 10, 'status' => AttributeDefinitionStatus::Active, 'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();

    return $definition->refresh();
}

function r17ValueOption(Company $company, AttributeDefinition $definition, string $code, AttributeOptionStatus $status = AttributeOptionStatus::Active): AttributeOption
{
    $option = new AttributeOption;
    $option->forceFill([
        'company_id' => $company->id, 'attribute_definition_id' => $definition->id,
        'label' => str($code)->headline()->toString(), 'code' => $code, 'sort_order' => 10, 'status' => $status,
    ])->save();

    return $option->refresh();
}

test('product sync writes every scalar select and multiselect type to its exact storage', function () {
    [$actor, $company, $product] = r17ValueContext();
    $definitions = [
        'text' => r17ValueDefinition($company, $actor, 'text', AttributeDataType::Text, AttributeScope::Product, ['min_length' => 2, 'max_length' => 20]),
        'integer' => r17ValueDefinition($company, $actor, 'integer', AttributeDataType::Integer, AttributeScope::Product, ['min' => '0', 'max' => '20']),
        'decimal' => r17ValueDefinition($company, $actor, 'decimal', AttributeDataType::Decimal, AttributeScope::Product, ['min' => '0']),
        'boolean' => r17ValueDefinition($company, $actor, 'boolean', AttributeDataType::Boolean, AttributeScope::Both),
        'date' => r17ValueDefinition($company, $actor, 'date', AttributeDataType::Date, AttributeScope::Product, ['min_date' => '2020-01-01']),
        'select' => r17ValueDefinition($company, $actor, 'select', AttributeDataType::Select, AttributeScope::Product),
        'multi' => r17ValueDefinition($company, $actor, 'multi', AttributeDataType::Multiselect, AttributeScope::Product, ['min_selections' => 1, 'max_selections' => 2]),
    ];
    $select = r17ValueOption($company, $definitions['select'], 'chosen');
    $multiA = r17ValueOption($company, $definitions['multi'], 'a');
    $multiB = r17ValueOption($company, $definitions['multi'], 'b');

    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [
        $definitions['text']->uuid => '  Durable  ',
        $definitions['integer']->uuid => '10',
        $definitions['decimal']->uuid => '1234567890123456.1234',
        $definitions['boolean']->uuid => '0',
        $definitions['date']->uuid => '2026-07-14',
        $definitions['select']->uuid => $select->id,
        $definitions['multi']->uuid => [$multiB->id, $multiA->id],
    ]);

    $values = ProductAttributeValue::query()->where('product_id', $product->id)->get()->keyBy('attribute_definition_id');
    expect($values)->toHaveCount(7)
        ->and($values[$definitions['text']->id]->value_text)->toBe('Durable')
        ->and($values[$definitions['integer']->id]->value_integer)->toBe(10)
        ->and($values[$definitions['decimal']->id]->value_decimal)->toBe('1234567890123456.1234')
        ->and($values[$definitions['boolean']->id]->value_boolean)->toBeFalse()
        ->and($values[$definitions['date']->id]->value_date?->format('Y-m-d'))->toBe('2026-07-14')
        ->and($values[$definitions['select']->id]->value_option_id)->toBe($select->id)
        ->and($values[$definitions['integer']->id]->value_text)->toBeNull()
        ->and($values[$definitions['integer']->id]->value_decimal)->toBeNull()
        ->and($values[$definitions['multi']->id]->selectedOptions()->pluck('attribute_options.id')->sort()->values()->all())
        ->toBe(collect([$multiA->id, $multiB->id])->sort()->values()->all());
});

test('variant sync is independent from Product values and enforces Product Variant ownership', function () {
    [$actor, $company, $product, $variant] = r17ValueContext();
    $both = r17ValueDefinition($company, $actor, 'both_text', AttributeDataType::Text, AttributeScope::Both);
    $variantOnly = r17ValueDefinition($company, $actor, 'variant_integer', AttributeDataType::Integer, AttributeScope::Variant);
    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [$both->uuid => 'Product value']);
    app(SyncVariantAttributeValuesAction::class)->execute($actor, $company, $product, $variant, [
        $both->uuid => 'Variant value', $variantOnly->uuid => '40',
    ]);

    expect(ProductAttributeValue::query()->where('product_id', $product->id)->where('attribute_definition_id', $both->id)->value('value_text'))->toBe('Product value')
        ->and(VariantAttributeValue::query()->where('product_variant_id', $variant->id)->where('attribute_definition_id', $both->id)->value('value_text'))->toBe('Variant value')
        ->and(VariantAttributeValue::query()->where('product_variant_id', $variant->id)->where('attribute_definition_id', $variantOnly->id)->value('value_integer'))->toBe(40);

    $foreignProduct = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Other', 'slug' => 'other', 'short_description' => null, 'description' => null, 'brand' => null, 'manufacturer' => null,
    ], ['name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0]);
    expect(fn () => app(SyncVariantAttributeValuesAction::class)->execute($actor, $company, $foreignProduct, $variant, []))
        ->toThrow(AttributeOperationException::class, 'unavailable');
});

test('scope enforcement rejects Product and Variant targets while both is accepted', function () {
    [$actor, $company] = r17ValueContext();
    $productOnly = r17ValueDefinition($company, $actor, 'product_only', AttributeDataType::Text, AttributeScope::Product);
    $variantOnly = r17ValueDefinition($company, $actor, 'variant_only', AttributeDataType::Text, AttributeScope::Variant);
    $both = r17ValueDefinition($company, $actor, 'both', AttributeDataType::Text, AttributeScope::Both);
    $validator = app(AttributeValueValidator::class);

    expect(fn () => $validator->normalize($company, $productOnly, AttributeScope::Variant, 'x'))->toThrow(AttributeOperationException::class, 'not available')
        ->and(fn () => $validator->normalize($company, $variantOnly, AttributeScope::Product, 'x'))->toThrow(AttributeOperationException::class, 'not available')
        ->and($validator->normalize($company, $both, AttributeScope::Product, 'x')->clear)->toBeFalse()
        ->and($validator->normalize($company, $both, AttributeScope::Variant, 'x')->clear)->toBeFalse();
});

test('wrong definition company and inactive options are rejected before persistence', function () {
    [$actor, $company, $product] = r17ValueContext();
    $select = r17ValueDefinition($company, $actor, 'select', AttributeDataType::Select, AttributeScope::Product);
    $archived = r17ValueOption($company, $select, 'old', AttributeOptionStatus::Archived);
    $otherDefinition = r17ValueDefinition($company, $actor, 'other_select', AttributeDataType::Select, AttributeScope::Product);
    $wrong = r17ValueOption($company, $otherDefinition, 'wrong');

    expect(fn () => app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [
        $select->uuid => $archived->id,
    ]))->toThrow(AttributeOperationException::class, 'unavailable')
        ->and(fn () => app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [
            $select->uuid => $wrong->id,
        ]))->toThrow(AttributeOperationException::class, 'unavailable')
        ->and(ProductAttributeValue::query()->count())->toBe(0);
});

test('failed full sync leaves old scalar and multiselect values and audit untouched', function () {
    [$actor, $company, $product] = r17ValueContext();
    $text = r17ValueDefinition($company, $actor, 'text', AttributeDataType::Text, AttributeScope::Product, ['max_length' => 10]);
    $multi = r17ValueDefinition($company, $actor, 'multi', AttributeDataType::Multiselect, AttributeScope::Product, ['min_selections' => 1]);
    $a = r17ValueOption($company, $multi, 'a');
    $b = r17ValueOption($company, $multi, 'b');
    $action = app(SyncProductAttributeValuesAction::class);
    $action->execute($actor, $company, $product, [$text->uuid => 'old', $multi->uuid => [$a->id]]);
    AuditLog::query()->delete();

    expect(fn () => $action->execute($actor, $company, $product, [$text->uuid => 'new', $multi->uuid => [$b->id, 999999]]))
        ->toThrow(AttributeOperationException::class);
    $textValue = ProductAttributeValue::query()->where('attribute_definition_id', $text->id)->sole();
    $multiValue = ProductAttributeValue::query()->where('attribute_definition_id', $multi->id)->sole();
    expect($textValue->value_text)->toBe('old')
        ->and($multiValue->selectedOptions()->pluck('attribute_options.id')->all())->toBe([$a->id])
        ->and(AuditLog::query()->count())->toBe(0);
});

test('clearing scalar select and multiselect deletes value rows and pivots', function () {
    [$actor, $company, $product] = r17ValueContext();
    $text = r17ValueDefinition($company, $actor, 'text', AttributeDataType::Text, AttributeScope::Product, required: true);
    $select = r17ValueDefinition($company, $actor, 'select', AttributeDataType::Select, AttributeScope::Product);
    $multi = r17ValueDefinition($company, $actor, 'multi', AttributeDataType::Multiselect, AttributeScope::Product);
    $one = r17ValueOption($company, $select, 'one');
    $many = r17ValueOption($company, $multi, 'many');
    $action = app(SyncProductAttributeValuesAction::class);
    $action->execute($actor, $company, $product, [$text->uuid => 'value', $select->uuid => $one->id, $multi->uuid => [$many->id]]);
    $action->execute($actor, $company, $product, [$text->uuid => '   ', $select->uuid => '', $multi->uuid => []]);

    expect(ProductAttributeValue::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(DB::table('product_attribute_value_options')->count())->toBe(0);
});

test('repeat sync is a true no-op and archived definition values remain visible in storage', function () {
    [$actor, $company, $product] = r17ValueContext();
    $text = r17ValueDefinition($company, $actor, 'text', AttributeDataType::Text, AttributeScope::Product);
    $action = app(SyncProductAttributeValuesAction::class);
    $action->execute($actor, $company, $product, [$text->uuid => 'same']);
    $value = ProductAttributeValue::query()->sole();
    $timestamp = $value->updated_at;
    $auditCount = AuditLog::query()->where('event', AuditEvent::CatalogProductAttributesUpdated->value)->count();
    $action->execute($actor, $company, $product, [$text->uuid => 'same']);
    expect(AuditLog::query()->where('event', AuditEvent::CatalogProductAttributesUpdated->value)->count())->toBe($auditCount)
        ->and($value->fresh()?->updated_at?->equalTo($timestamp))->toBeTrue();

    $text->forceFill(['status' => AttributeDefinitionStatus::Archived])->save();
    $action->execute($actor, $company, $product, []);
    expect(ProductAttributeValue::query()->whereKey($value)->exists())->toBeTrue();
});

test('typed validation handles strict boolean integer decimal date and multiselect boundaries', function () {
    [$actor, $company] = r17ValueContext();
    $integer = r17ValueDefinition($company, $actor, 'integer', AttributeDataType::Integer, AttributeScope::Product, ['min' => '1', 'max' => '2']);
    $decimal = r17ValueDefinition($company, $actor, 'decimal', AttributeDataType::Decimal, AttributeScope::Product);
    $boolean = r17ValueDefinition($company, $actor, 'boolean', AttributeDataType::Boolean, AttributeScope::Product);
    $date = r17ValueDefinition($company, $actor, 'date', AttributeDataType::Date, AttributeScope::Product);
    $validator = app(AttributeValueValidator::class);

    expect(fn () => $validator->normalize($company, $integer, AttributeScope::Product, '1.5'))->toThrow(AttributeOperationException::class, 'integer')
        ->and(fn () => $validator->normalize($company, $integer, AttributeScope::Product, '3'))->toThrow(AttributeOperationException::class, 'outside')
        ->and(fn () => $validator->normalize($company, $decimal, AttributeScope::Product, '1.12345'))->toThrow(AttributeOperationException::class, 'DECIMAL')
        ->and(fn () => $validator->normalize($company, $boolean, AttributeScope::Product, 'yes'))->toThrow(AttributeOperationException::class, 'Choose Yes')
        ->and(fn () => $validator->normalize($company, $date, AttributeScope::Product, '2026-02-30'))->toThrow(AttributeOperationException::class, 'real calendar date')
        ->and($validator->normalize($company, $integer, AttributeScope::Product, '2')->columns['value_integer'])->toBe(2);
});

test('editor assigns values while viewer is denied', function (CompanyRole $role, bool $allowed) {
    [$actor, $company, $product] = r17ValueContext($role);
    $text = r17ValueDefinition($company, $actor, 'text', AttributeDataType::Text, AttributeScope::Product);
    $callback = fn () => app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [$text->uuid => 'value']);

    if ($allowed) {
        expect($callback()->attributeValues)->toHaveCount(1);
    } else {
        expect($callback)->toThrow(AuthorizationException::class);
    }
})->with(['editor' => [CompanyRole::Editor, true], 'viewer' => [CompanyRole::Viewer, false]]);
