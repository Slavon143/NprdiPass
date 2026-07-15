<?php

namespace App\Queries\Catalog;

use App\Data\Catalog\Search\CatalogAttributeFilterCriteria;
use App\Data\Catalog\Search\CatalogProductSearchCriteria;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use App\Support\Catalog\Search\CatalogSearchStringNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

class ProductCatalogQuery
{
    public function __construct(
        private readonly CatalogIdentifierNormalizer $identifierNormalizer,
        private readonly CatalogSearchStringNormalizer $searchNormalizer,
    ) {}

    public function build(Company $company, CatalogProductSearchCriteria $criteria): Builder
    {
        $query = Product::query()
            ->forCompany($company)
            ->with(['primaryCategory:id,uuid,name,status', 'defaultVariant:id,uuid,product_id,name,sku,gtin,mpn,status', 'primaryMedia:id,uuid,product_id,alt_text,mime_type'])
            ->withCount(['categories', 'variants', 'productMedia']);

        $this->applyProductStatuses($query, $criteria);
        $this->applyKeyword($query, $company, $criteria);
        $this->applyVariantStatuses($query, $company, $criteria);
        $this->applyCategoryFilter($query, $company, $criteria);
        $this->applyMetadataFilters($query, $criteria);
        $this->applyAttributeFilters($query, $company, $criteria);
        $this->applyMissingDataFilters($query, $company, $criteria);
        $this->applyReadinessFilter($query, $company, $criteria);
        $this->applySorting($query, $company, $criteria);

        return $query;
    }

    private function applyProductStatuses(Builder $query, CatalogProductSearchCriteria $criteria): void
    {
        $query->whereIn($query->qualifyColumn('status'), $criteria->productStatuses);
    }

    private function applyKeyword(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        if ($criteria->query === '') {
            return;
        }

        $terms = $this->keywordTerms($criteria->query);
        $generic = $this->searchNormalizer->isGenericSearchable($criteria->query);

        $query->where(function (Builder $query) use ($company, $criteria, $terms, $generic): void {
            $query->where($query->qualifyColumn('slug_normalized'), $this->slugCandidate($criteria->query));

            $this->orVariantMatch($query, $company, function (QueryBuilder $variant) use ($criteria): void {
                $variant->where('sku_normalized', $this->skuCandidate($criteria->query))
                    ->orWhere('gtin', $this->gtinCandidate($criteria->query))
                    ->orWhere('mpn', $criteria->query)
                    ->orWhere('name', $criteria->query);
            });

            if (! $generic) {
                return;
            }

            foreach ($terms as $term) {
                $contains = '%'.$this->searchNormalizer->escapeLike($term).'%';
                $prefix = $this->searchNormalizer->escapeLike($term).'%';

                $query->orWhere($query->qualifyColumn('name'), 'LIKE', $contains)
                    ->orWhere($query->qualifyColumn('brand'), 'LIKE', $contains)
                    ->orWhere($query->qualifyColumn('manufacturer'), 'LIKE', $contains)
                    ->orWhere($query->qualifyColumn('slug_normalized'), 'LIKE', $prefix);

                $this->orVariantMatch($query, $company, function (QueryBuilder $variant) use ($prefix, $contains): void {
                    $variant->where('sku_normalized', 'LIKE', $prefix)
                        ->orWhere('mpn', 'LIKE', $prefix)
                        ->orWhere('name', 'LIKE', $contains);
                });

                $this->orCategoryMatch($query, $company, function (QueryBuilder $category) use ($prefix, $contains): void {
                    $category->where('categories.slug_normalized', 'LIKE', $prefix)
                        ->orWhere('categories.name', 'LIKE', $contains);
                });
            }
        });
    }

    private function applyVariantStatuses(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        if ($criteria->variantStatuses === []) {
            return;
        }

        $query->whereExists(function (QueryBuilder $exists) use ($company, $criteria): void {
            $exists->selectRaw('1')
                ->from('product_variants')
                ->where('product_variants.company_id', $company->getKey())
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereIn('product_variants.status', $criteria->variantStatuses)
                ->whereNull('product_variants.deleted_at');
        });
    }

    private function applyCategoryFilter(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        if ($criteria->categoryIds === []) {
            return;
        }

        if ($criteria->categoryMode === 'primary') {
            $query->whereIn($query->qualifyColumn('primary_category_id'), $criteria->categoryIds);

            return;
        }

        $query->whereExists(function (QueryBuilder $exists) use ($company, $criteria): void {
            $exists->selectRaw('1')
                ->from('category_product')
                ->where('category_product.company_id', $company->getKey())
                ->whereColumn('category_product.product_id', 'products.id')
                ->whereIn('category_product.category_id', $criteria->categoryIds);
        });
    }

    private function applyMetadataFilters(Builder $query, CatalogProductSearchCriteria $criteria): void
    {
        if ($criteria->brand !== null) {
            $query->where($query->qualifyColumn('brand'), $criteria->brand);
        }

        if ($criteria->manufacturer !== null) {
            $query->where($query->qualifyColumn('manufacturer'), $criteria->manufacturer);
        }
    }

    private function applyAttributeFilters(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        foreach ($criteria->attributeFilters as $filter) {
            $query->where(function (Builder $query) use ($company, $filter): void {
                $appliesToProduct = in_array($filter->scope, [AttributeScope::Product, AttributeScope::Both], true);
                $appliesToVariant = in_array($filter->scope, [AttributeScope::Variant, AttributeScope::Both], true);

                if ($appliesToProduct) {
                    $this->whereProductAttribute($query, $company, $filter, 'or');
                }

                if ($appliesToVariant) {
                    $this->whereVariantAttribute($query, $company, $filter, 'or');
                }
            });
        }
    }

    private function applyMissingDataFilters(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        foreach ($criteria->missingData as $missing) {
            match ($missing) {
                'primary_category' => $query->whereNull($query->qualifyColumn('primary_category_id')),
                'default_variant' => $this->whereMissingDefaultVariant($query, $company),
                'primary_image' => $query->whereNull($query->qualifyColumn('primary_media_id')),
                'variant_sku' => $this->whereMissingDefaultVariantSku($query, $company),
                'required_product_attribute' => $this->whereMissingRequiredProductAttribute($query, $company),
                'required_variant_attribute' => $this->whereMissingRequiredVariantAttribute($query, $company),
                default => null,
            };
        }
    }

    private function applyReadinessFilter(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        if ($criteria->readiness === 'any') {
            return;
        }

        $this->applyReadyPredicates($query, $company, $criteria->readiness === 'ready');
    }

    private function applyReadyPredicates(Builder $query, Company $company, bool $positive): void
    {
        $callback = function (Builder $ready) use ($company): void {
            $ready->where('products.status', 'draft')
                ->whereRaw("TRIM(products.name) <> ''")
                ->whereRaw("TRIM(products.slug) <> ''")
                ->whereNotNull('products.primary_category_id')
                ->whereExists(function (QueryBuilder $exists) use ($company): void {
                    $exists->selectRaw('1')
                        ->from('categories')
                        ->join('category_product', function ($join) use ($company): void {
                            $join->on('category_product.category_id', '=', 'categories.id')
                                ->where('category_product.company_id', $company->getKey())
                                ->whereColumn('category_product.product_id', 'products.id');
                        })
                        ->where('categories.company_id', $company->getKey())
                        ->where('categories.status', CategoryStatus::Active->value)
                        ->whereColumn('categories.id', 'products.primary_category_id');
                });

            $this->whereHasAvailableVariant($ready, $company);
            $this->whereHasValidDefaultVariant($ready, $company);
            $this->whereRequiredProductAttributesPresent($ready, $company);
            $this->whereRequiredVariantAttributesPresent($ready, $company);
        };

        if ($positive) {
            $query->where($callback);

            return;
        }

        $query->whereNot($callback);
    }

    private function applySorting(Builder $query, Company $company, CatalogProductSearchCriteria $criteria): void
    {
        $direction = $criteria->direction === 'asc' ? 'asc' : 'desc';

        if ($criteria->sort === 'relevance' && $criteria->query !== '') {
            $this->orderByRelevance($query, $company, $criteria->query);
        } elseif ($criteria->sort === 'name') {
            $query->orderBy($query->qualifyColumn('name'), $direction);
        } elseif ($criteria->sort === 'brand') {
            $query->orderBy($query->qualifyColumn('brand'), $direction);
        } elseif ($criteria->sort === 'created') {
            $query->orderBy($query->qualifyColumn('created_at'), $direction);
        } elseif ($criteria->sort === 'variant_count') {
            $query->orderBy('variants_count', $direction);
        } else {
            $query->orderBy($query->qualifyColumn('updated_at'), $direction);
        }

        $query->orderBy($query->qualifyColumn('id'), $direction);
    }

    private function orderByRelevance(Builder $query, Company $company, string $rawQuery): void
    {
        $sku = $this->skuCandidate($rawQuery);
        $gtin = $this->gtinCandidate($rawQuery);
        $slug = $this->slugCandidate($rawQuery);
        $contains = '%'.$this->searchNormalizer->escapeLike($rawQuery).'%';

        $query->orderByRaw(
            "CASE
                WHEN EXISTS (SELECT 1 FROM product_variants v WHERE v.company_id = ? AND v.product_id = products.id AND v.deleted_at IS NULL AND v.sku_normalized = ?) THEN 0
                WHEN EXISTS (SELECT 1 FROM product_variants v WHERE v.company_id = ? AND v.product_id = products.id AND v.deleted_at IS NULL AND v.gtin = ?) THEN 1
                WHEN EXISTS (SELECT 1 FROM product_variants v WHERE v.company_id = ? AND v.product_id = products.id AND v.deleted_at IS NULL AND v.mpn = ?) THEN 2
                WHEN products.slug_normalized = ? THEN 3
                WHEN EXISTS (SELECT 1 FROM product_variants v WHERE v.company_id = ? AND v.product_id = products.id AND v.deleted_at IS NULL AND v.name = ?) THEN 4
                WHEN products.name LIKE ? THEN 5
                ELSE 9
            END ASC",
            [$company->getKey(), $sku, $company->getKey(), $gtin, $company->getKey(), $rawQuery, $slug, $company->getKey(), $rawQuery, $contains],
        );
    }

    private function whereProductAttribute(Builder $query, Company $company, CatalogAttributeFilterCriteria $filter, string $boolean = 'and'): void
    {
        if ($filter->type === AttributeDataType::Boolean && $filter->boolean === 'not_set') {
            $query->{$boolean.'Where'}(function (Builder $nested) use ($company, $filter): void {
                $nested->whereNotExists(function (QueryBuilder $exists) use ($company, $filter): void {
                    $exists->selectRaw('1')
                        ->from('product_attribute_values')
                        ->where('product_attribute_values.company_id', $company->getKey())
                        ->whereColumn('product_attribute_values.product_id', 'products.id')
                        ->where('product_attribute_values.attribute_definition_id', $filter->definitionId);
                })->orWhereExists(function (QueryBuilder $exists) use ($company, $filter): void {
                    $exists->selectRaw('1')
                        ->from('product_attribute_values')
                        ->where('product_attribute_values.company_id', $company->getKey())
                        ->whereColumn('product_attribute_values.product_id', 'products.id')
                        ->where('product_attribute_values.attribute_definition_id', $filter->definitionId)
                        ->whereNull('product_attribute_values.value_boolean');
                });
            });

            return;
        }

        $query->{$boolean.'Where'}(function (Builder $nested) use ($company, $filter): void {
            $nested->whereExists(function (QueryBuilder $exists) use ($company, $filter): void {
                $exists->selectRaw('1')
                    ->from('product_attribute_values')
                    ->where('product_attribute_values.company_id', $company->getKey())
                    ->whereColumn('product_attribute_values.product_id', 'products.id')
                    ->where('product_attribute_values.attribute_definition_id', $filter->definitionId);

                $this->applyAttributeValuePredicate($exists, 'product_attribute_values', 'product_attribute_value_options', 'product_attribute_value_id', $filter);
            });
        });
    }

    private function whereVariantAttribute(Builder $query, Company $company, CatalogAttributeFilterCriteria $filter, string $boolean = 'and'): void
    {
        $query->{$boolean.'Where'}(function (Builder $nested) use ($company, $filter): void {
            $nested->whereExists(function (QueryBuilder $variant) use ($company, $filter): void {
                $variant->selectRaw('1')
                    ->from('product_variants')
                    ->where('product_variants.company_id', $company->getKey())
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.status', '!=', ProductVariantStatus::Archived->value)
                    ->whereNull('product_variants.deleted_at');

                if ($filter->type === AttributeDataType::Boolean && $filter->boolean === 'not_set') {
                    $variant->where(function (QueryBuilder $variant) use ($company, $filter): void {
                        $variant->whereNotExists(function (QueryBuilder $exists) use ($company, $filter): void {
                            $exists->selectRaw('1')
                                ->from('variant_attribute_values')
                                ->where('variant_attribute_values.company_id', $company->getKey())
                                ->whereColumn('variant_attribute_values.product_variant_id', 'product_variants.id')
                                ->where('variant_attribute_values.attribute_definition_id', $filter->definitionId);
                        })->orWhereExists(function (QueryBuilder $exists) use ($company, $filter): void {
                            $exists->selectRaw('1')
                                ->from('variant_attribute_values')
                                ->where('variant_attribute_values.company_id', $company->getKey())
                                ->whereColumn('variant_attribute_values.product_variant_id', 'product_variants.id')
                                ->where('variant_attribute_values.attribute_definition_id', $filter->definitionId)
                                ->whereNull('variant_attribute_values.value_boolean');
                        });
                    });

                    return;
                }

                $variant->whereExists(function (QueryBuilder $exists) use ($company, $filter): void {
                    $exists->selectRaw('1')
                        ->from('variant_attribute_values')
                        ->where('variant_attribute_values.company_id', $company->getKey())
                        ->whereColumn('variant_attribute_values.product_variant_id', 'product_variants.id')
                        ->where('variant_attribute_values.attribute_definition_id', $filter->definitionId);

                    $this->applyAttributeValuePredicate($exists, 'variant_attribute_values', 'variant_attribute_value_options', 'variant_attribute_value_id', $filter);
                });
            });
        });
    }

    private function applyAttributeValuePredicate(
        QueryBuilder $query,
        string $valueTable,
        string $pivotTable,
        string $pivotValueColumn,
        CatalogAttributeFilterCriteria $filter,
    ): void {
        match ($filter->type) {
            AttributeDataType::Select => $query->whereIn("{$valueTable}.value_option_id", $filter->optionIds),
            AttributeDataType::Multiselect => $query->whereExists(function (QueryBuilder $exists) use ($valueTable, $pivotTable, $pivotValueColumn, $filter): void {
                $exists->selectRaw('1')
                    ->from($pivotTable)
                    ->whereColumn("{$pivotTable}.{$pivotValueColumn}", "{$valueTable}.id")
                    ->whereColumn("{$pivotTable}.attribute_definition_id", "{$valueTable}.attribute_definition_id")
                    ->whereColumn("{$pivotTable}.company_id", "{$valueTable}.company_id")
                    ->whereIn("{$pivotTable}.attribute_option_id", $filter->optionIds);
            }),
            AttributeDataType::Boolean => $query->where("{$valueTable}.value_boolean", $filter->boolean === '1'),
            AttributeDataType::Integer => $this->applyRange($query, "{$valueTable}.value_integer", $filter),
            AttributeDataType::Decimal => $this->applyRange($query, "{$valueTable}.value_decimal", $filter),
            AttributeDataType::Date => $this->applyDateRange($query, "{$valueTable}.value_date", $filter),
            AttributeDataType::Text => null,
        };
    }

    private function applyRange(QueryBuilder $query, string $column, CatalogAttributeFilterCriteria $filter): void
    {
        if ($filter->min !== null) {
            $query->where($column, '>=', $filter->min);
        }

        if ($filter->max !== null) {
            $query->where($column, '<=', $filter->max);
        }
    }

    private function applyDateRange(QueryBuilder $query, string $column, CatalogAttributeFilterCriteria $filter): void
    {
        if ($filter->from !== null) {
            $query->where($column, '>=', $filter->from);
        }

        if ($filter->to !== null) {
            $query->where($column, '<=', $filter->to);
        }
    }

    private function whereMissingDefaultVariant(Builder $query, Company $company): void
    {
        $query->where(function (Builder $query) use ($company): void {
            $query->whereNull('products.default_variant_id')
                ->orWhereNotExists(function (QueryBuilder $exists) use ($company): void {
                    $exists->selectRaw('1')
                        ->from('product_variants')
                        ->where('product_variants.company_id', $company->getKey())
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->whereColumn('product_variants.id', 'products.default_variant_id')
                        ->whereNull('product_variants.deleted_at');
                });
        });
    }

    private function whereMissingDefaultVariantSku(Builder $query, Company $company): void
    {
        $query->whereExists(function (QueryBuilder $exists) use ($company): void {
            $exists->selectRaw('1')
                ->from('product_variants')
                ->where('product_variants.company_id', $company->getKey())
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereColumn('product_variants.id', 'products.default_variant_id')
                ->where(function (QueryBuilder $variant): void {
                    $variant->whereNull('product_variants.sku')
                        ->orWhereRaw("TRIM(product_variants.sku) = ''");
                })
                ->whereNull('product_variants.deleted_at');
        });
    }

    private function whereMissingRequiredProductAttribute(Builder $query, Company $company): void
    {
        foreach ($this->requiredDefinitions($company, [AttributeScope::Product, AttributeScope::Both]) as $definition) {
            $query->whereNotExists(function (QueryBuilder $exists) use ($company, $definition): void {
                $exists->selectRaw('1')
                    ->from('product_attribute_values')
                    ->where('product_attribute_values.company_id', $company->getKey())
                    ->whereColumn('product_attribute_values.product_id', 'products.id')
                    ->where('product_attribute_values.attribute_definition_id', $definition->getKey());

                $this->applyRequiredValuePredicate($exists, 'product_attribute_values', 'product_attribute_value_options', $definition);
            });
        }
    }

    private function whereMissingRequiredVariantAttribute(Builder $query, Company $company): void
    {
        foreach ($this->requiredDefinitions($company, [AttributeScope::Variant, AttributeScope::Both]) as $definition) {
            $query->whereNotExists(function (QueryBuilder $exists) use ($company, $definition): void {
                $exists->selectRaw('1')
                    ->from('variant_attribute_values')
                    ->join('product_variants', function ($join) use ($company): void {
                        $join->on('product_variants.id', '=', 'variant_attribute_values.product_variant_id')
                            ->where('product_variants.company_id', $company->getKey())
                            ->whereColumn('product_variants.product_id', 'products.id')
                            ->whereColumn('product_variants.id', 'products.default_variant_id')
                            ->where('product_variants.status', '!=', ProductVariantStatus::Archived->value)
                            ->whereNull('product_variants.deleted_at');
                    })
                    ->where('variant_attribute_values.company_id', $company->getKey())
                    ->where('variant_attribute_values.attribute_definition_id', $definition->getKey());

                $this->applyRequiredValuePredicate($exists, 'variant_attribute_values', 'variant_attribute_value_options', $definition);
            });
        }
    }

    private function whereRequiredProductAttributesPresent(Builder $query, Company $company): void
    {
        foreach ($this->requiredDefinitions($company, [AttributeScope::Product, AttributeScope::Both]) as $definition) {
            $query->whereExists(function (QueryBuilder $exists) use ($company, $definition): void {
                $exists->selectRaw('1')
                    ->from('product_attribute_values')
                    ->where('product_attribute_values.company_id', $company->getKey())
                    ->whereColumn('product_attribute_values.product_id', 'products.id')
                    ->where('product_attribute_values.attribute_definition_id', $definition->getKey());
                $this->applyRequiredValuePredicate($exists, 'product_attribute_values', 'product_attribute_value_options', $definition);
            });
        }
    }

    private function whereRequiredVariantAttributesPresent(Builder $query, Company $company): void
    {
        foreach ($this->requiredDefinitions($company, [AttributeScope::Variant, AttributeScope::Both]) as $definition) {
            $query->whereExists(function (QueryBuilder $exists) use ($company, $definition): void {
                $exists->selectRaw('1')
                    ->from('variant_attribute_values')
                    ->join('product_variants', function ($join) use ($company): void {
                        $join->on('product_variants.id', '=', 'variant_attribute_values.product_variant_id')
                            ->where('product_variants.company_id', $company->getKey())
                            ->whereColumn('product_variants.product_id', 'products.id')
                            ->whereColumn('product_variants.id', 'products.default_variant_id')
                            ->where('product_variants.status', '!=', ProductVariantStatus::Archived->value)
                            ->whereNull('product_variants.deleted_at');
                    })
                    ->where('variant_attribute_values.company_id', $company->getKey())
                    ->where('variant_attribute_values.attribute_definition_id', $definition->getKey());
                $this->applyRequiredValuePredicate($exists, 'variant_attribute_values', 'variant_attribute_value_options', $definition);
            });
        }
    }

    private function applyRequiredValuePredicate(
        QueryBuilder $query,
        string $valueTable,
        string $pivotTable,
        AttributeDefinition $definition,
    ): void {
        match ($definition->type) {
            AttributeDataType::Text => $query->whereNotNull("{$valueTable}.value_text")->whereRaw("TRIM({$valueTable}.value_text) <> ''"),
            AttributeDataType::Integer => $query->whereNotNull("{$valueTable}.value_integer"),
            AttributeDataType::Decimal => $query->whereNotNull("{$valueTable}.value_decimal"),
            AttributeDataType::Boolean => $query->whereNotNull("{$valueTable}.value_boolean"),
            AttributeDataType::Date => $query->whereNotNull("{$valueTable}.value_date"),
            AttributeDataType::Select => $query->whereNotNull("{$valueTable}.value_option_id")->whereExists(function (QueryBuilder $exists) use ($valueTable, $definition): void {
                $exists->selectRaw('1')
                    ->from('attribute_options')
                    ->whereColumn('attribute_options.id', "{$valueTable}.value_option_id")
                    ->whereColumn('attribute_options.company_id', "{$valueTable}.company_id")
                    ->where('attribute_options.attribute_definition_id', $definition->getKey())
                    ->where('attribute_options.status', AttributeOptionStatus::Active->value);
            }),
            AttributeDataType::Multiselect => $query->whereExists(function (QueryBuilder $exists) use ($valueTable, $pivotTable): void {
                $exists->selectRaw('1')
                    ->from($pivotTable)
                    ->join('attribute_options', function ($join) use ($pivotTable): void {
                        $join->on('attribute_options.id', '=', "{$pivotTable}.attribute_option_id")
                            ->whereColumn('attribute_options.company_id', "{$pivotTable}.company_id")
                            ->whereColumn('attribute_options.attribute_definition_id', "{$pivotTable}.attribute_definition_id")
                            ->where('attribute_options.status', AttributeOptionStatus::Active->value);
                    })
                    ->whereColumn("{$pivotTable}.company_id", "{$valueTable}.company_id")
                    ->whereColumn("{$pivotTable}.attribute_definition_id", "{$valueTable}.attribute_definition_id")
                    ->whereColumn("{$pivotTable}.".($pivotTable === 'product_attribute_value_options' ? 'product_attribute_value_id' : 'variant_attribute_value_id'), "{$valueTable}.id");
            }),
        };
    }

    /**
     * @param  list<AttributeScope>  $scopes
     * @return list<AttributeDefinition>
     */
    private function requiredDefinitions(Company $company, array $scopes): array
    {
        return AttributeDefinition::query()
            ->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->where('required', true)
            ->whereIn('scope', array_map(fn (AttributeScope $scope): string => $scope->value, $scopes))
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function whereHasAvailableVariant(Builder $query, Company $company): void
    {
        $query->whereExists(function (QueryBuilder $exists) use ($company): void {
            $exists->selectRaw('1')
                ->from('product_variants')
                ->where('product_variants.company_id', $company->getKey())
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('product_variants.status', '!=', ProductVariantStatus::Archived->value)
                ->whereNull('product_variants.deleted_at');
        });
    }

    private function whereHasValidDefaultVariant(Builder $query, Company $company): void
    {
        $query->whereExists(function (QueryBuilder $exists) use ($company): void {
            $exists->selectRaw('1')
                ->from('product_variants')
                ->where('product_variants.company_id', $company->getKey())
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereColumn('product_variants.id', 'products.default_variant_id')
                ->where('product_variants.status', '!=', ProductVariantStatus::Archived->value)
                ->whereNull('product_variants.deleted_at');
        });
    }

    private function orVariantMatch(Builder $query, Company $company, callable $predicate): void
    {
        $query->orWhereExists(function (QueryBuilder $exists) use ($company, $predicate): void {
            $exists->selectRaw('1')
                ->from('product_variants')
                ->where('product_variants.company_id', $company->getKey())
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereNull('product_variants.deleted_at')
                ->where(function (QueryBuilder $variant) use ($predicate): void {
                    $predicate($variant);
                });
        });
    }

    private function orCategoryMatch(Builder $query, Company $company, callable $predicate): void
    {
        $query->orWhereExists(function (QueryBuilder $exists) use ($company, $predicate): void {
            $exists->selectRaw('1')
                ->from('category_product')
                ->join('categories', function ($join) use ($company): void {
                    $join->on('categories.id', '=', 'category_product.category_id')
                        ->where('categories.company_id', $company->getKey());
                })
                ->where('category_product.company_id', $company->getKey())
                ->whereColumn('category_product.product_id', 'products.id')
                ->where(function (QueryBuilder $category) use ($predicate): void {
                    $predicate($category);
                });
        });
    }

    /** @return list<string> */
    private function keywordTerms(string $query): array
    {
        return array_slice(array_values(array_filter(explode(' ', $query), fn (string $term): bool => $term !== '')), 0, 5);
    }

    private function skuCandidate(string $query): string
    {
        try {
            return $this->identifierNormalizer->normalizeSku($query);
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    private function gtinCandidate(string $query): string
    {
        return preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $query) === 1 ? $query : '';
    }

    private function slugCandidate(string $query): string
    {
        return $this->identifierNormalizer->normalizeProductSlug($query);
    }
}
