<?php

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Attributes\SyncVariantAttributeValuesAction;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeScope;
use App\Models\AuditLog;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\VariantAttributeValue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

test('repeated boolean sync preserves timestamp and audit while explicit clear still removes value', function (string $target, bool $boolean) {
    $instant = now()->startOfSecond();
    Date::setTestNow($instant);
    try {
        [$actor, $company, $product, $variant] = r17ValueContext();
        $definition = r17ValueDefinition($company, $actor, 'flag', AttributeDataType::Boolean, AttributeScope::Both);
        $action = app($target === 'product' ? SyncProductAttributeValuesAction::class : SyncVariantAttributeValuesAction::class);
        $model = $target === 'product' ? ProductAttributeValue::class : VariantAttributeValue::class;
        $event = $target === 'product' ? 'catalog.product.attributes.updated' : 'catalog.variant.attributes.updated';
        $arguments = $target === 'product' ? [$actor, $company, $product] : [$actor, $company, $product, $variant];
        $payload = [$definition->uuid => $boolean ? '1' : '0'];
        $action->execute(...[...$arguments, $payload]);
        $before = $model::query()->forCompany($company)->sole()->getRawOriginal();
        Date::setTestNow($instant->addMinutes(10));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $action->execute(...[...$arguments, $payload]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $pivotTable = $target === 'product' ? 'product_attribute_value_options' : 'variant_attribute_value_options';
        $writes = array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'update `'.(new $model)->getTable().'`')
            || str_starts_with(strtolower($query['query']), 'delete from `'.$pivotTable.'`'));
        expect($model::query()->forCompany($company)->sole()->getRawOriginal())->toBe($before)
            ->and($writes)->toBeEmpty()
            ->and(AuditLog::query()->where('company_id', $company->id)->where('event', $event)->count())->toBe(1);
        $action->execute(...[...$arguments, []]);
        expect($model::query()->forCompany($company)->count())->toBe(0)
            ->and(AuditLog::query()->where('company_id', $company->id)->where('event', $event)->count())->toBe(2);
    } finally {
        Date::setTestNow();
    }
})->with([['product', false], ['product', true], ['variant', false], ['variant', true]]);
