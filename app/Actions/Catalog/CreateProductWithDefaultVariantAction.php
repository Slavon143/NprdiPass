<?php

namespace App\Actions\Catalog;

use App\Actions\Catalog\Exceptions\CatalogIdentifierConflict;
use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateProductWithDefaultVariantAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CatalogIdentifierNormalizer $normalizer,
        private readonly AuditLogger $auditLogger,
        private readonly ProductAggregateCreator $aggregateCreator,
    ) {}

    /**
     * @param  array<string, mixed>  $productData
     * @param  array<string, mixed>  $variantData
     */
    public function execute(
        User $actor,
        Company $company,
        array $productData,
        array $variantData,
    ): Product {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $company = $freshCompany;
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogCreate);

        $name = trim((string) ($productData['name'] ?? ''));
        $slug = $this->normalizer->normalizeProductSlug((string) ($productData['slug'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255 || $slug === '') {
            throw new InvalidArgumentException('Product name and slug are required.');
        }

        $sku = isset($variantData['sku']) ? trim((string) $variantData['sku']) : null;
        $sku = $sku === '' ? null : $sku;
        $skuNormalized = $sku === null ? null : $this->normalizer->normalizeSku($sku);
        $gtin = $this->normalizer->normalizeGtin(
            isset($variantData['gtin']) ? (string) $variantData['gtin'] : null,
        );
        $mpn = $this->normalizer->normalizeMpn(
            isset($variantData['mpn']) ? (string) $variantData['mpn'] : null,
        );

        try {
            return DB::transaction(function () use (
                $actor,
                $company,
                $productData,
                $variantData,
                $name,
                $slug,
                $sku,
                $skuNormalized,
                $gtin,
                $mpn,
            ): Product {
                $product = $this->aggregateCreator->create($actor, $company, [
                    ...$productData,
                    'name' => $name,
                    'slug' => $slug,
                ], [
                    ...$variantData,
                    'sku' => $sku,
                    'sku_normalized' => $skuNormalized,
                    'gtin' => $gtin,
                    'mpn' => $mpn,
                    'sort_order' => max(0, (int) ($variantData['sort_order'] ?? 0)),
                ]);
                $variant = $product->defaultVariant;

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogProductCreated,
                    $actor,
                    $product,
                    [
                        'product_uuid' => $product->getAttribute('uuid'),
                        'product_name' => $product->getAttribute('name'),
                        'status' => ProductStatus::Draft->value,
                        'default_variant_uuid' => $variant?->getAttribute('uuid'),
                    ],
                );
                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogVariantCreated,
                    $actor,
                    $variant ?? $product,
                    [
                        'product_uuid' => $product->getAttribute('uuid'),
                        'variant_uuid' => $variant?->getAttribute('uuid'),
                    ],
                );

                return $product->refresh()->load('defaultVariant');
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new CatalogIdentifierConflict($exception);
            }

            throw $exception;
        }
    }
}
