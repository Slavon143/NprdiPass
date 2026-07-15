<?php

namespace App\Http\Requests\Catalog\Search;

use App\Data\Catalog\Search\CatalogAttributeFilterCriteria;
use App\Data\Catalog\Search\CatalogProductSearchCriteria;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Services\Catalog\CategoryHierarchyService;
use App\Support\Catalog\Search\CatalogSearchStringNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SearchProductsRequest extends FormRequest
{
    public const MAX_CATEGORIES = 20;

    public const MAX_ATTRIBUTE_FILTERS = 12;

    public const MAX_OPTIONS_PER_ATTRIBUTE = 20;

    /** @var array<string, AttributeDefinition>|null */
    private ?array $filterableDefinitions = null;

    public function authorize(): bool
    {
        $company = app(CurrentCompany::class)->get();

        return $company instanceof Company
            && $this->user()?->can('viewAny', [Product::class, $company]) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $productStatuses = ['draft', 'active', 'archived'];
        $variantStatuses = ['draft', 'active', 'archived'];

        return [
            'q' => ['nullable', 'string', 'max:200'],
            'product_statuses' => ['nullable', 'array', 'max:3'],
            'product_statuses.*' => ['string', Rule::in($productStatuses)],
            'variant_statuses' => ['nullable', 'array', 'max:3'],
            'variant_statuses.*' => ['string', Rule::in($variantStatuses)],
            'category_uuids' => ['nullable', 'array', 'max:'.self::MAX_CATEGORIES],
            'category_uuids.*' => ['uuid', 'distinct'],
            'category_mode' => ['nullable', Rule::in(['primary', 'any'])],
            'include_descendants' => ['nullable', 'boolean'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'readiness' => ['nullable', Rule::in(['any', 'ready', 'not_ready'])],
            'missing_data' => ['nullable', 'array', 'max:6'],
            'missing_data.*' => ['string', Rule::in([
                'primary_category',
                'default_variant',
                'primary_image',
                'variant_sku',
                'required_product_attribute',
                'required_variant_attribute',
            ])],
            'attributes' => ['nullable', 'array', 'max:'.self::MAX_ATTRIBUTE_FILTERS],
            'attributes.*.definition' => ['required_with:attributes', 'uuid', 'distinct'],
            'attributes.*.options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS_PER_ATTRIBUTE],
            'attributes.*.options.*' => ['integer', 'min:1', 'distinct'],
            'attributes.*.boolean' => ['nullable', Rule::in(['1', '0', 'not_set'])],
            'attributes.*.min' => ['nullable', 'regex:/^-?\d+(?:\.\d{1,4})?$/'],
            'attributes.*.max' => ['nullable', 'regex:/^-?\d+(?:\.\d{1,4})?$/'],
            'attributes.*.from' => ['nullable', 'date_format:Y-m-d'],
            'attributes.*.to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(['relevance', 'updated', 'created', 'name', 'brand', 'variant_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $productStatuses = $this->arrayInput($this->input('product_statuses', []));

        if ($productStatuses === [] && is_string($this->input('status')) && $this->input('status') !== 'all') {
            $productStatuses = [(string) $this->input('status')];
        }

        $attributes = [];
        foreach ($this->arrayInput($this->input('attributes', [])) as $key => $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $definition = $filter['definition'] ?? (is_string($key) ? $key : null);
            if (! is_string($definition) || $definition === '') {
                continue;
            }

            $attributes[] = [
                ...$filter,
                'definition' => $definition,
                'options' => $this->filterFilledArray($filter['options'] ?? []),
                'boolean' => $this->filledString($filter['boolean'] ?? null),
                'min' => $this->filledString($filter['min'] ?? null),
                'max' => $this->filledString($filter['max'] ?? null),
                'from' => $this->filledString($filter['from'] ?? null),
                'to' => $this->filledString($filter['to'] ?? null),
            ];
        }

        $this->merge([
            'product_statuses' => array_values(array_unique($productStatuses)),
            'variant_statuses' => array_values(array_unique($this->arrayInput($this->input('variant_statuses', [])))),
            'category_uuids' => array_values(array_unique($this->filterFilledArray($this->input('category_uuids', [])))),
            'missing_data' => array_values(array_unique($this->arrayInput($this->input('missing_data', [])))),
            'attributes' => $attributes,
            'brand' => $this->filledString($this->input('brand')),
            'manufacturer' => $this->filledString($this->input('manufacturer')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $company = app(CurrentCompany::class)->get();
            if (! $company instanceof Company) {
                return;
            }

            $this->validateCategories($validator, $company);
            $this->validateAttributes($validator, $company);
        });
    }

    public function toCriteria(
        Company $company,
        CategoryHierarchyService $hierarchy,
        CatalogSearchStringNormalizer $searchNormalizer,
    ): CatalogProductSearchCriteria {
        $validated = $this->validated();
        $categories = $this->categories($company);
        $categoryIds = $categories->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($this->boolean('include_descendants') && $categories->isNotEmpty()) {
            $allCategories = Category::query()->forCompany($company)->get();
            foreach ($categories as $category) {
                $categoryIds = [
                    ...$categoryIds,
                    ...$hierarchy->descendantIds($company, $category, $allCategories),
                ];
            }
        }

        $categoryIds = array_values(array_unique($categoryIds));
        $productStatuses = $this->stringList($validated['product_statuses'] ?? []);

        return new CatalogProductSearchCriteria(
            query: $searchNormalizer->normalize($validated['q'] ?? ''),
            productStatuses: $productStatuses === [] ? ['draft', 'active'] : $productStatuses,
            variantStatuses: $this->stringList($validated['variant_statuses'] ?? []),
            categoryIds: $categoryIds,
            categoryUuids: $this->stringList($validated['category_uuids'] ?? []),
            categoryMode: (string) ($validated['category_mode'] ?? 'primary'),
            includeDescendants: $this->boolean('include_descendants'),
            brand: $this->filledString($validated['brand'] ?? null),
            manufacturer: $this->filledString($validated['manufacturer'] ?? null),
            readiness: (string) ($validated['readiness'] ?? 'any'),
            missingData: $this->stringList($validated['missing_data'] ?? []),
            attributeFilters: $this->attributeCriteria($company, $validated['attributes'] ?? []),
            sort: (string) ($validated['sort'] ?? 'updated'),
            direction: (string) ($validated['direction'] ?? 'desc'),
            perPage: (int) ($validated['per_page'] ?? 25),
        );
    }

    private function validateCategories(Validator $validator, Company $company): void
    {
        $uuids = $this->stringList($this->input('category_uuids', []));
        if ($uuids === []) {
            return;
        }

        $count = Category::query()->forCompany($company)->whereIn('uuid', $uuids)->count();
        if ($count !== count($uuids)) {
            $validator->errors()->add('category_uuids', 'One or more selected categories are unavailable.');
        }
    }

    private function validateAttributes(Validator $validator, Company $company): void
    {
        $filters = $this->arrayInput('attributes');
        if ($filters === []) {
            return;
        }

        $definitions = $this->filterableDefinitions($company);

        foreach ($filters as $index => $filter) {
            if (! is_array($filter) || ! is_string($filter['definition'] ?? null)) {
                continue;
            }

            $definition = $definitions[$filter['definition']] ?? null;
            if (! $definition instanceof AttributeDefinition) {
                $validator->errors()->add("attributes.{$index}.definition", 'The selected attribute filter is unavailable.');

                continue;
            }

            $this->validateAttributeValue($validator, $company, $definition, $filter, (int) $index);
        }
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function validateAttributeValue(
        Validator $validator,
        Company $company,
        AttributeDefinition $definition,
        array $filter,
        int $index,
    ): void {
        if ($definition->type === AttributeDataType::Text) {
            $validator->errors()->add("attributes.{$index}.definition", 'Text attribute filters are not supported in catalog listing.');

            return;
        }

        $options = $this->integerList($filter['options'] ?? []);
        if (in_array($definition->type, [AttributeDataType::Select, AttributeDataType::Multiselect], true)) {
            if ($options === []) {
                return;
            }

            $count = AttributeOption::query()
                ->where('company_id', $company->getKey())
                ->where('attribute_definition_id', $definition->getKey())
                ->where('status', AttributeOptionStatus::Active->value)
                ->whereIn('id', $options)
                ->count();
            if ($count !== count($options)) {
                $validator->errors()->add("attributes.{$index}.options", 'One or more selected attribute options are unavailable.');
            }
        } elseif ($options !== []) {
            $validator->errors()->add("attributes.{$index}.options", 'Options are only supported by select attributes.');
        }

        if ($definition->type !== AttributeDataType::Boolean && $this->filledString($filter['boolean'] ?? null) !== null) {
            $validator->errors()->add("attributes.{$index}.boolean", 'Boolean filters are only supported by boolean attributes.');
        }

        if (! in_array($definition->type, [AttributeDataType::Integer, AttributeDataType::Decimal], true)
            && ($this->filledString($filter['min'] ?? null) !== null || $this->filledString($filter['max'] ?? null) !== null)) {
            $validator->errors()->add("attributes.{$index}.min", 'Numeric ranges are only supported by numeric attributes.');
        }

        if ($this->filledString($filter['min'] ?? null) !== null
            && $this->filledString($filter['max'] ?? null) !== null
            && $this->compareDecimalStrings((string) $filter['min'], (string) $filter['max']) > 0) {
            $validator->errors()->add("attributes.{$index}.min", 'The minimum attribute filter may not exceed the maximum.');
        }

        if ($definition->type !== AttributeDataType::Date
            && ($this->filledString($filter['from'] ?? null) !== null || $this->filledString($filter['to'] ?? null) !== null)) {
            $validator->errors()->add("attributes.{$index}.from", 'Date ranges are only supported by date attributes.');
        }

        if ($this->filledString($filter['from'] ?? null) !== null
            && $this->filledString($filter['to'] ?? null) !== null
            && strcmp((string) $filter['from'], (string) $filter['to']) > 0) {
            $validator->errors()->add("attributes.{$index}.from", 'The start date may not be after the end date.');
        }
    }

    /** @return array<string, AttributeDefinition> */
    private function filterableDefinitions(Company $company): array
    {
        if ($this->filterableDefinitions !== null) {
            return $this->filterableDefinitions;
        }

        $this->filterableDefinitions = AttributeDefinition::query()
            ->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->where('filterable', true)
            ->with(['options' => fn ($query) => $query->ordered()])
            ->get()
            ->keyBy('uuid')
            ->all();

        return $this->filterableDefinitions;
    }

    /**
     * @param  array<int|string, mixed>  $filters
     * @return list<CatalogAttributeFilterCriteria>
     */
    private function attributeCriteria(Company $company, mixed $filters): array
    {
        $criteria = [];
        $definitions = $this->filterableDefinitions($company);

        foreach ($this->arrayInput($filters) as $filter) {
            if (! is_array($filter) || ! is_string($filter['definition'] ?? null)) {
                continue;
            }

            $definition = $definitions[$filter['definition']] ?? null;
            if (! $definition instanceof AttributeDefinition) {
                continue;
            }

            $criterion = new CatalogAttributeFilterCriteria(
                definitionId: (int) $definition->getKey(),
                definitionUuid: $definition->uuid,
                label: $definition->name,
                type: $definition->type,
                scope: $definition->scope,
                optionIds: $this->integerList($filter['options'] ?? []),
                boolean: $this->filledString($filter['boolean'] ?? null),
                min: $this->filledString($filter['min'] ?? null),
                max: $this->filledString($filter['max'] ?? null),
                from: $this->filledString($filter['from'] ?? null),
                to: $this->filledString($filter['to'] ?? null),
            );

            if ($criterion->hasValue()) {
                $criteria[] = $criterion;
            }
        }

        return $criteria;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Category> */
    private function categories(Company $company)
    {
        $uuids = $this->stringList($this->validated('category_uuids', []));

        return Category::query()->forCompany($company)->whereIn('uuid', $uuids)->get();
    }

    /** @return list<string> */
    private function arrayInput(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function filterFilledArray(mixed $value): array
    {
        return array_values(array_filter($this->arrayInput($value), fn (mixed $item): bool => $this->filledString($item) !== null));
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function compareDecimalStrings(string $left, string $right): int
    {
        return (float) $left <=> (float) $right;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter($this->arrayInput($value), 'is_string'));
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', array_filter($this->arrayInput($value), fn (mixed $item): bool => is_numeric($item)))));
    }
}
