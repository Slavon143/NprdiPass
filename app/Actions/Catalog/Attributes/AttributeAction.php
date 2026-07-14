<?php

namespace App\Actions\Catalog\Attributes;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogLifecycleGuard;
use App\Support\Catalog\AttributeValueValidator;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

abstract class AttributeAction
{
    public const MAX_DEFINITIONS = 500;

    public const MAX_OPTIONS = 200;

    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly CatalogIdentifierNormalizer $normalizer,
        protected readonly AuditLogger $auditLogger,
        protected readonly AttributeValueValidator $validator,
        protected readonly CatalogLifecycleGuard $lifecycle,
    ) {}

    protected function authorize(User $actor, Company $company, CompanyPermission $permission = CompanyPermission::CatalogManageAttributes): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, $permission);

        return $freshCompany;
    }

    protected function assertDefinitionTenant(Company $company, AttributeDefinition $definition): void
    {
        if ((int) $definition->company_id !== (int) $company->getKey()) {
            throw AttributeOperationException::tenantMismatch();
        }
    }

    protected function assertOptionOwner(Company $company, AttributeDefinition $definition, AttributeOption $option): void
    {
        if ((int) $option->company_id !== (int) $company->getKey()
            || (int) $option->attribute_definition_id !== (int) $definition->getKey()) {
            throw AttributeOperationException::optionMismatch();
        }
    }

    protected function assertProductEditable(Product $product, ?ProductVariant $variant = null): void
    {
        if ($variant === null) {
            $this->lifecycle->assertProductEditable($product);

            return;
        }

        $this->lifecycle->assertVariantEditable($product, $variant);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, code: string, description: string|null, type: AttributeDataType, scope: AttributeScope, unit: string|null, required: bool, filterable: bool, searchable: bool, validation_rules: array<string, int|float|string>|null, sort_order: int}
     */
    protected function definitionData(array $data, ?AttributeDefinition $fallback = null): array
    {
        $name = $this->string($data, 'name', 255, $fallback?->name, false);
        $rawCode = $this->string($data, 'code', 100, $fallback?->code, false);

        if ($name === null || $rawCode === null) {
            throw AttributeOperationException::invalid('attribute', 'Name and code are required.');
        }

        try {
            $code = $this->normalizer->normalizeAttributeCode($rawCode);
        } catch (InvalidArgumentException $exception) {
            throw AttributeOperationException::invalid('code', $exception->getMessage());
        }

        if ($code === '') {
            throw AttributeOperationException::invalid('code', 'Code is required.');
        }

        $type = $this->dataType($data['type'] ?? $fallback?->type);
        $scope = $this->scope($data['scope'] ?? $fallback?->scope);
        $rules = $this->validator->normalizeRules($type, $data['validation_rules'] ?? $fallback?->validation_rules);

        return [
            'name' => $name,
            'code' => $code,
            'description' => $this->string($data, 'description', 500, $fallback?->description, true),
            'type' => $type,
            'scope' => $scope,
            'unit' => $this->string($data, 'unit', 50, $fallback?->unit, true),
            'required' => $this->boolean($data, 'required', $fallback === null ? false : $fallback->required),
            'filterable' => $this->boolean($data, 'filterable', $fallback === null ? false : $fallback->filterable),
            'searchable' => $this->boolean($data, 'searchable', $fallback === null ? false : $fallback->searchable),
            'validation_rules' => $rules === [] ? null : $rules,
            'sort_order' => $this->sortOrder($data['sort_order'] ?? ($fallback === null ? 0 : $fallback->sort_order)),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function optionData(array $data, ?AttributeOption $fallback = null): array
    {
        $label = $this->string($data, 'label', 255, $fallback?->label, false);
        $rawCode = $this->string($data, 'code', 100, $fallback?->code, false);

        if ($label === null || $rawCode === null) {
            throw AttributeOperationException::invalid('option', 'Label and code are required.');
        }

        try {
            $code = $this->normalizer->normalizeOptionCode($rawCode);
        } catch (InvalidArgumentException $exception) {
            throw AttributeOperationException::invalid('code', $exception->getMessage());
        }

        if ($code === '') {
            throw AttributeOperationException::invalid('code', 'Code is required.');
        }

        return ['label' => $label, 'code' => $code, 'sort_order' => $this->sortOrder($data['sort_order'] ?? ($fallback === null ? 0 : $fallback->sort_order))];
    }

    protected function mapDuplicate(QueryException $exception, string $field = 'code'): ?AttributeOperationException
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            ? AttributeOperationException::codeConflict($field, $exception)
            : null;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $field, int $maximum, ?string $fallback, bool $nullable): ?string
    {
        $value = array_key_exists($field, $data) ? $data[$field] : $fallback;

        if ($value === null || $value === '') {
            if ($nullable) {
                return null;
            }

            throw AttributeOperationException::invalid($field, ucfirst($field).' is required.');
        }

        if (! is_string($value)) {
            throw AttributeOperationException::invalid($field, ucfirst($field).' must be text.');
        }

        $value = trim($value);

        if ($value === '') {
            return $nullable ? null : throw AttributeOperationException::invalid($field, ucfirst($field).' is required.');
        }

        if (mb_strlen($value) > $maximum) {
            throw AttributeOperationException::invalid($field, ucfirst($field)." may not exceed {$maximum} characters.");
        }

        return $value;
    }

    private function dataType(mixed $value): AttributeDataType
    {
        if ($value instanceof AttributeDataType) {
            return $value;
        }

        $result = is_string($value) ? AttributeDataType::tryFrom($value) : null;

        return $result ?? throw AttributeOperationException::invalid('type', 'Choose a valid data type.');
    }

    private function scope(mixed $value): AttributeScope
    {
        if ($value instanceof AttributeScope) {
            return $value;
        }

        $result = is_string($value) ? AttributeScope::tryFrom($value) : null;

        return $result ?? throw AttributeOperationException::invalid('scope', 'Choose a valid scope.');
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $field, bool $fallback): bool
    {
        if (! array_key_exists($field, $data)) {
            return $fallback;
        }

        $value = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? throw AttributeOperationException::invalid($field, ucfirst($field).' must be true or false.');
    }

    private function sortOrder(mixed $value): int
    {
        $sortOrder = filter_var($value, FILTER_VALIDATE_INT);

        if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 4294967295) {
            throw AttributeOperationException::invalid('sort_order', 'Sort order must be a non-negative integer.');
        }

        return $sortOrder;
    }
}
